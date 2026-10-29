<?php

declare(strict_types=1);

namespace LINGNER\LinTypo3Health\Middleware;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\Domain\Site\Site as SolrSite;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository as SolrSiteRepository;
use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use LINGNER\LinTypo3Health\Formatter\PrometheusFormatter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\CronCommand\CronCommand;

/**
 * GET /_api/health: database, PHP-FPM, scheduler, Solr and system metrics for monitoring.
 *
 * - Token from the environment or the extension configuration, sent as bearer
 *   via `Authorization` or `X-Authorization`; disabled without token.
 * - Works on shared hosting (FastCGI, open_basedir, disable_functions):
 *   values that cannot be read are null.
 * - 200 for ok/degraded, 503 if the database is unreachable.
 * - `?format=prometheus` returns the Prometheus text format, always with 200.
 * - Counters are cumulative since server start, graph their rate.
 * - Runs before frontend user authentication
 */
class HealthMiddleware implements MiddlewareInterface
{
    private const PATH = '/_api/health';
    private const EXTENSION_KEY = 'lin_typo3_health';
    private const TOKEN_VARIABLE = 'TYPO3_HEALTH_ENDPOINT_BEARER';

    private const DB_STATUS_VARIABLES = [
        'Threads_connected',
        'Threads_running',
        'Max_used_connections',
        'Connection_errors_max_connections',
        'Aborted_connects',
        'Innodb_row_lock_current_waits',
        'Slow_queries',
        'Questions',
        'Uptime',
    ];

    // Thresholds for "degraded"
    private const WARN_CONNECTION_RATIO = 0.8;
    private const WARN_MEMORY_RATIO = 0.9;
    private const WARN_DISK_RATIO = 0.9;
    private const WARN_LOAD_PER_CPU = 2.0;
    private const WARN_ROW_LOCK_WAITS = 5;
    // Relative to the configured task interval: scheduler idle (shortest interval), task overdue, task running
    private const WARN_SCHEDULER_INTERVAL_RATIO = 1.5;
    private const WARN_SOLR_HEAP_RATIO = 0.9;
    private const WARN_SOLR_QUEUE_STALLED_SECONDS = 3600;

    // EXT:solr PingFailedException codes: no HTTP response vs. error response
    private const SOLR_PING_NO_RESPONSE = 1645716101;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (rtrim($request->getUri()->getPath(), '/') !== self::PATH) {
            return $handler->handle($request);
        }

        $expectedToken = $this->expectedToken($request);
        if ($expectedToken === '') {
            return $handler->handle($request);
        }

        if (!$this->isAuthorized($request, $expectedToken)) {
            return $this->json(['error' => 'Unauthorized'], 401)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        if (strtoupper($request->getMethod()) !== 'GET') {
            return $this->json(['error' => 'Method not allowed'], 405);
        }

        $startedAt = microtime(true);
        $warnings = [];

        $database = $this->collectDatabase($warnings);
        $fpm = $this->collectFpm($warnings);
        $scheduler = $database['reachable'] ? $this->collectScheduler($warnings) : null;
        $solr = $database['reachable'] ? $this->collectSolr($warnings) : null;
        $system = $this->collectSystem($warnings);

        $status = match (true) {
            !$database['reachable'] => 'error',
            $warnings !== [] => 'degraded',
            default => 'ok',
        };

        $payload = [
            'status' => $status,
            'warnings' => $warnings,
            'hostname' => function_exists('gethostname') ? (gethostname() ?: null) : null,
            'time' => gmdate('c'),
            'typo3' => [
                'version' => (new Typo3Version())->getVersion(),
            ],
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'memoryLimit' => ini_get('memory_limit'),
                'maxExecutionTime' => (int) ini_get('max_execution_time'),
            ],
            'database' => $database,
            'fpm' => $fpm,
            'scheduler' => $scheduler,
            'solr' => $solr,
            'system' => $system,
            'durationMs' => round((microtime(true) - $startedAt) * 1000, 1),
        ];

        if (($request->getQueryParams()['format'] ?? null) === 'prometheus') {
            return $this->prometheus($payload);
        }

        return $this->json($payload, $status === 'error' ? 503 : 200);
    }

    /**
     * Environment first, then the extension configuration. Behind mod_rewrite,
     * CGI/FastCGI only see `SetEnv` variables with a `REDIRECT_` prefix.
     */
    private function expectedToken(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        foreach ([self::TOKEN_VARIABLE, 'REDIRECT_' . self::TOKEN_VARIABLE] as $name) {
            $token = trim((string) (getenv($name) ?: ($serverParams[$name] ?? '')));
            if ($token !== '') {
                return $token;
            }
        }
        return trim((string) $this->extensionSetting('token', ''));
    }

    private function extensionSetting(string $path, mixed $default): mixed
    {
        try {
            return $this->extensionConfiguration->get(self::EXTENSION_KEY, $path);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * The key stays the same while the message carries the current values,
     * so consumers can follow one warning across checks.
     *
     * @return array{key: string, message: string}
     */
    private function warning(string $key, string $message): array
    {
        return ['key' => $key, 'message' => $message];
    }

    private function isAuthorized(ServerRequestInterface $request, string $expectedToken): bool
    {
        $credentials = [
            $request->getHeaderLine('Authorization'),
            $request->getHeaderLine('X-Authorization'),
            (string) ($request->getServerParams()['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
        ];
        foreach ($credentials as $credential) {
            if (preg_match('/^Bearer\s+(.+)$/i', $credential, $matches)
                && hash_equals($expectedToken, trim($matches[1]))) {
                return true;
            }
        }
        return false;
    }

    /**
     * MySQL or MariaDB. Only `SELECT 1` decides about reachability, shared
     * database servers may deny the other queries.
     *
     * With a per-account `max_user_connections` (the session value carries
     * the account limit) the ratio refers to the own connections, otherwise
     * to `Threads_connected` of the whole server.
     *
     * @param list<array{key: string, message: string}> $warnings
     * @return array<string, mixed>
     */
    private function collectDatabase(array &$warnings): array
    {
        $startedAt = microtime(true);

        try {
            $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
            $connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $e) {
            // Message may contain credentials, log only
            $this->logger->error('Health check: database not reachable', ['exception' => $e]);
            $warnings[] = $this->warning('database.not_reachable', 'Database not reachable');
            return [
                'reachable' => false,
            ];
        }
        $latencyMs = round((microtime(true) - $startedAt) * 1000, 1);

        $status = $this->readDatabaseStatus($connection);
        $maxConnections = $this->queryDatabase('max_connections', static fn(): int => (int) $connection->executeQuery('SELECT @@max_connections')->fetchOne());
        $maxUserConnections = $this->queryDatabase('max_user_connections', static fn(): int => (int) $connection->executeQuery('SELECT @@max_user_connections')->fetchOne());
        // Without the PROCESS privilege the process list only contains the own threads anyway
        $userConnections = $maxUserConnections > 0 ? $this->queryDatabase('user connections', static fn(): int => (int) $connection->executeQuery(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER = SUBSTRING_INDEX(CURRENT_USER(), '@', 1)"
        )->fetchOne()) : null;

        [$connectionLimit, $connectionRatio] = match (true) {
            $userConnections !== null => ['max_user_connections', round($userConnections / $maxUserConnections, 3)],
            $maxConnections > 0 && isset($status['Threads_connected']) => ['max_connections', round($status['Threads_connected'] / $maxConnections, 3)],
            default => [null, null],
        };

        if ($connectionRatio !== null && $connectionRatio >= self::WARN_CONNECTION_RATIO) {
            $warnings[] = $this->warning('database.connections_high', sprintf('Database connections at %d%% of %s', (int) ($connectionRatio * 100), $connectionLimit));
        }
        if (($status['Innodb_row_lock_current_waits'] ?? 0) >= self::WARN_ROW_LOCK_WAITS) {
            $warnings[] = $this->warning('database.row_lock_waits_pending', sprintf('%d InnoDB row lock waits pending', $status['Innodb_row_lock_current_waits']));
        }

        return [
            'reachable' => true,
            'latencyMs' => $latencyMs,
            'maxConnections' => $maxConnections,
            'maxUserConnections' => $maxUserConnections,
            'userConnections' => $userConnections,
            'connectionLimit' => $connectionLimit,
            'connectionRatio' => $connectionRatio,
            'status' => $status,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function readDatabaseStatus(Connection $connection): array
    {
        $placeholders = implode(',', array_fill(0, count(self::DB_STATUS_VARIABLES), '?'));
        $rows = $this->queryDatabase('status variables', static fn(): array => $connection->executeQuery(
            'SHOW GLOBAL STATUS WHERE Variable_name IN (' . $placeholders . ')',
            self::DB_STATUS_VARIABLES
        )->fetchAllAssociative()) ?? [];

        $status = [];
        foreach ($rows as $row) {
            $status[(string) $row['Variable_name']] = (int) $row['Value'];
        }
        return $status;
    }

    /**
     * Runs a query that may be denied, null on failure.
     *
     * @template T
     * @param callable(): T $query
     * @return T|null
     */
    private function queryDatabase(string $label, callable $query): mixed
    {
        try {
            return $query();
        } catch (\Throwable $e) {
            $this->logger->warning('Health check: database ' . $label . ' not readable', ['exception' => $e]);
            return null;
        }
    }

    /**
     * Listen queue > 0 means all workers are busy.
     *
     * @param list<array{key: string, message: string}> $warnings
     * @return array<string, int|string>|null
     */
    private function collectFpm(array &$warnings): ?array
    {
        if (!function_exists('fpm_get_status')) {
            return null;
        }
        $status = fpm_get_status();
        if (!is_array($status)) {
            return null;
        }

        $fpm = [
            'pool' => (string) $status['pool'],
            'processManager' => (string) $status['process-manager'],
            'startSince' => (int) $status['start-since'],
            'acceptedConnections' => (int) $status['accepted-conn'],
            'listenQueue' => (int) $status['listen-queue'],
            'maxListenQueue' => (int) $status['max-listen-queue'],
            'listenQueueLength' => (int) $status['listen-queue-len'],
            'activeProcesses' => (int) $status['active-processes'],
            'idleProcesses' => (int) $status['idle-processes'],
            'totalProcesses' => (int) $status['total-processes'],
            'maxActiveProcesses' => (int) $status['max-active-processes'],
            'maxChildrenReached' => (int) $status['max-children-reached'],
            'slowRequests' => (int) $status['slow-requests'],
        ];

        if ($fpm['listenQueue'] > 0) {
            $warnings[] = $this->warning('fpm.requests_waiting', sprintf('%d requests waiting for a PHP-FPM worker', $fpm['listenQueue']));
        }

        return $fpm;
    }

    /**
     * Last cron run and state of all enabled tasks. Failure messages are
     * omitted, they may contain paths.
     *
     * Thresholds follow the configured task intervals, so an hourly cron on
     * shared hosting only warns when a task is configured more often than
     * that: the scheduler is idle after 150 % of the shortest interval, a
     * task is overdue or running too long after 150 % of its own interval.
     * Single-run tasks have no interval and are not checked.
     *
     * @param list<array{key: string, message: string}> $warnings
     * @return array<string, mixed>|null
     */
    private function collectScheduler(array &$warnings): ?array
    {
        try {
            $lastRunValue = $this->connectionPool->getConnectionForTable('sys_registry')
                ->select(['entry_value'], 'sys_registry', ['entry_namespace' => 'tx_scheduler', 'entry_key' => 'lastRun'])
                ->fetchOne();
            $rows = $this->connectionPool->getConnectionForTable('tx_scheduler_task')
                ->select(
                    ['*'],
                    'tx_scheduler_task',
                    ['deleted' => 0, 'disable' => 0],
                    [],
                    ['uid' => 'ASC']
                )
                ->fetchAllAssociative();
        } catch (\Throwable $e) {
            $this->logger->error('Health check: scheduler not readable', ['exception' => $e]);
            return null;
        }

        $now = time();
        $lastRun = is_string($lastRunValue) ? unserialize($lastRunValue, ['allowed_classes' => false]) : null;
        $lastRunEnd = is_array($lastRun) ? (int) ($lastRun['end'] ?? 0) : 0;

        $tasks = [];
        foreach ($rows as $row) {
            $executions = $row['serialized_executions'] ? unserialize((string) $row['serialized_executions'], ['allowed_classes' => false]) : [];
            $runningSince = is_array($executions) && $executions !== [] ? (int) min($executions) : null;
            $failed = trim((string) $row['lastexecution_failure']) !== '';
            $nextExecution = (int) $row['nextexecution'];
            $interval = $this->taskInterval($this->taskSchedule($row), $nextExecution);
            $threshold = $interval !== null ? (int) ($interval * self::WARN_SCHEDULER_INTERVAL_RATIO) : null;
            $overdue = $threshold !== null && $runningSince === null && $nextExecution > 0 && $now - $nextExecution > $threshold;
            $name = $this->taskName($row);

            if ($failed) {
                $warnings[] = $this->warning('scheduler.task_failed.' . $row['uid'], sprintf('Task %d (%s) failed', $row['uid'], $name));
            }
            if ($threshold !== null && $runningSince !== null && $now - $runningSince > $threshold) {
                $warnings[] = $this->warning('scheduler.task_running_long.' . $row['uid'], sprintf('Task %d (%s) running for %d minutes', $row['uid'], $name, intdiv($now - $runningSince, 60)));
            }

            $tasks[] = [
                'uid' => (int) $row['uid'],
                'name' => $name,
                'description' => (string) $row['description'],
                'intervalSeconds' => $interval,
                'lastExecution' => $row['lastexecution_time'] ? gmdate('c', (int) $row['lastexecution_time']) : null,
                'nextExecution' => $nextExecution ? gmdate('c', $nextExecution) : null,
                'runningSince' => $runningSince ? gmdate('c', $runningSince) : null,
                'failed' => $failed,
                'overdue' => $overdue,
            ];
        }

        $intervals = array_filter(array_column($tasks, 'intervalSeconds'));
        if ($intervals !== []) {
            $shortestInterval = min($intervals);
            if ($lastRunEnd === 0) {
                $warnings[] = $this->warning('scheduler.never_run', 'Scheduler has never run');
            } elseif ($now - $lastRunEnd > $shortestInterval * self::WARN_SCHEDULER_INTERVAL_RATIO) {
                $warnings[] = $this->warning('scheduler.not_running', sprintf(
                    'Scheduler has not run for %d minutes, shortest task interval is %d minutes',
                    intdiv($now - $lastRunEnd, 60),
                    intdiv($shortestInterval, 60)
                ));
            }
        }

        $overdueCount = count(array_filter($tasks, static fn(array $task): bool => $task['overdue']));
        if ($overdueCount > 0) {
            $warnings[] = $this->warning('scheduler.tasks_overdue', sprintf('%d scheduler tasks overdue', $overdueCount));
        }

        return [
            'lastRun' => $lastRunEnd ? gmdate('c', $lastRunEnd) : null,
            'lastRunType' => is_array($lastRun) ? ($lastRun['type'] ?? null) : null,
            'tasks' => $tasks,
        ];
    }

    /**
     * Cores of all sites, one server info per Solr server and the index queue.
     * Null without EXT:solr. Messages may contain hosts, so they are logged only.
     *
     * @param list<array{key: string, message: string}> $warnings
     * @return array<string, mixed>|null
     */
    private function collectSolr(array &$warnings): ?array
    {
        if (!ExtensionManagementUtility::isLoaded('solr')) {
            return null;
        }

        try {
            [$cores, $servers] = $this->collectSolrCores($warnings);
            $indexQueue = $this->collectSolrIndexQueue($warnings);
        } catch (\Throwable $e) {
            $this->logger->error('Health check: Solr status not readable', ['exception' => $e]);
            $warnings[] = $this->warning('solr.status_not_readable', 'Solr status not readable');
            return null;
        }

        return [
            'cores' => $cores,
            'servers' => $servers,
            'indexQueue' => $indexQueue,
        ];
    }

    /**
     * Once a server gives no HTTP response, its remaining cores are not pinged:
     * a dead server costs one connect timeout instead of one per core.
     *
     * @param list<array{key: string, message: string}> $warnings
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function collectSolrCores(array &$warnings): array
    {
        // EXT:solr is optional, its classes are only loaded once this runs
        $siteRepository = GeneralUtility::makeInstance(SolrSiteRepository::class);
        $connectionManager = GeneralUtility::makeInstance(ConnectionManager::class);

        $cores = [];
        $servers = [];
        $deadServers = [];
        foreach ($siteRepository->getAvailableSites() as $site) {
            foreach ($connectionManager->getConnectionsBySite($site) as $languageId => $connection) {
                $endpoint = $connection->getEndpoint('read');
                $server = $endpoint->getHost() . ':' . $endpoint->getPort();
                $coreName = (string) $endpoint->getCore();
                if (isset($cores[$server . '/' . $coreName])) {
                    continue;
                }

                $core = [
                    'core' => $coreName,
                    'language' => $this->languageCode($site, (int) $languageId),
                    'reachable' => false,
                    'pingMs' => null,
                    'documents' => null,
                ];

                if (!isset($deadServers[$server])) {
                    try {
                        $core['pingMs'] = round($connection->getReadService()->getPingRoundTripRuntime(false), 1);
                        $core['reachable'] = true;
                        $core['documents'] = $this->countSolrDocuments($connection);
                        if (!array_key_exists($server, $servers)) {
                            $servers[$server] = $this->readSolrServer($connection);
                        }
                    } catch (\Throwable $e) {
                        if ($e->getCode() === self::SOLR_PING_NO_RESPONSE) {
                            $deadServers[$server] = true;
                        }
                        $this->logger->warning('Health check: Solr core not reachable', ['core' => $coreName, 'exception' => $e]);
                    }
                }

                if (!$core['reachable']) {
                    $warnings[] = $this->warning('solr.core_not_reachable.' . $server . '/' . $coreName, sprintf('Solr core %s not reachable', $coreName));
                }
                $cores[$server . '/' . $coreName] = $core;
            }
        }

        foreach ($servers as $server => $info) {
            if (($info['heapRatio'] ?? 0) >= self::WARN_SOLR_HEAP_RATIO) {
                $warnings[] = $this->warning('solr.heap_high.' . $server, sprintf('Solr heap at %d%%', (int) ($info['heapRatio'] * 100)));
            }
        }

        return [array_values($cores), array_values(array_filter($servers))];
    }

    /**
     * Documents in the core, a rows=0 query is cheaper than the luke handler.
     */
    private function countSolrDocuments(SolrConnection $connection): int
    {
        $client = $connection->getReadService()->getClient();
        $query = $client->createSelect()->setQuery('*:*')->setRows(0);
        return $client->select($query)->getNumFound();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSolrServer(SolrConnection $connection): ?array
    {
        try {
            $system = $connection->getAdminService()->getSystemInformation();
        } catch (\Throwable $e) {
            $this->logger->warning('Health check: Solr system info not readable', ['exception' => $e]);
            return null;
        }

        // Property names contain dashes, so the response objects are read as arrays.
        $lucene = (array) ($system->lucene ?? []);
        $heap = (array) ($system->jvm->memory->raw ?? []);
        $used = (int) ($heap['used'] ?? 0);
        $max = (int) ($heap['max'] ?? 0);

        return [
            'version' => (string) ($lucene['solr-spec-version'] ?? ''),
            'heapUsedBytes' => $used,
            'heapMaxBytes' => $max,
            'heapRatio' => $max > 0 ? round($used / $max, 3) : null,
        ];
    }

    /**
     * Same criteria as the EXT:solr queue statistics, read without its internal API.
     *
     * @param list<array{key: string, message: string}> $warnings
     * @return array<string, int|null>
     */
    private function collectSolrIndexQueue(array &$warnings): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_solr_indexqueue_item');
        $row = $queryBuilder
            ->selectLiteral(
                'COUNT(*) AS total',
                "SUM(CASE WHEN errors <> '' THEN 1 ELSE 0 END) AS failed",
                "SUM(CASE WHEN errors = '' AND changed > indexed THEN 1 ELSE 0 END) AS pending",
                "MIN(CASE WHEN errors = '' AND changed > indexed THEN changed END) AS oldest_pending"
            )
            ->from('tx_solr_indexqueue_item')
            ->executeQuery()
            ->fetchAssociative();

        $oldestPending = (int) ($row['oldest_pending'] ?? 0);
        $oldestPendingAge = $oldestPending > 0 ? max(0, time() - $oldestPending) : null;
        if ($oldestPendingAge !== null && $oldestPendingAge > self::WARN_SOLR_QUEUE_STALLED_SECONDS) {
            $warnings[] = $this->warning('solr.index_queue_stalled', sprintf('Solr index queue stalled for %d minutes', intdiv($oldestPendingAge, 60)));
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'oldestPendingSeconds' => $oldestPendingAge,
        ];
    }

    private function languageCode(SolrSite $site, int $languageId): string
    {
        try {
            return $site->getTypo3SiteObject()->getLanguageById($languageId)->getLocale()->getLanguageCode();
        } catch (\Throwable) {
            return (string) $languageId;
        }
    }

    /**
     * Interval and cron expression of a task. TYPO3 v14 stores them as JSON in
     * `execution_details`; v12/v13 and rows the v14 upgrade wizard has not
     * migrated yet only in the serialized task, which is read without
     * unserializing it.
     *
     * @param array<string, mixed> $row
     * @return array{interval: int, cronCmd: string}|null
     */
    private function taskSchedule(array $row): ?array
    {
        $details = json_decode((string) ($row['execution_details'] ?? ''), true);
        if (is_array($details)) {
            return [
                'interval' => (int) ($details['interval'] ?? 0),
                'cronCmd' => (string) ($details['cronCmd'] ?? ''),
            ];
        }

        // Only the Execution object, tasks may have own properties of the same name
        if (!preg_match('/Scheduler\\\\Execution":\d+:\{(.*?)\}/s', (string) ($row['serialized_task_object'] ?? ''), $execution)) {
            return null;
        }
        // Protected properties are serialized as "\0*\0name"
        $cronCmd = preg_match('/\x00\*\x00cronCmd";s:\d+:"([^"]*)"/', $execution[1], $matches) ? $matches[1] : '';
        $interval = preg_match('/\x00\*\x00interval";(?:i:(\d+)|s:\d+:"(\d+)")/', $execution[1], $matches)
            ? (int) ($matches[1] ?: ($matches[2] ?? 0))
            : 0;
        return [
            'interval' => $interval,
            'cronCmd' => $cronCmd,
        ];
    }

    /**
     * Seconds between two runs, null for single-run tasks.
     *
     * @param array{interval: int, cronCmd: string}|null $schedule
     */
    private function taskInterval(?array $schedule, int $nextExecution): ?int
    {
        if ($schedule === null) {
            return null;
        }

        $cronCmd = trim($schedule['cronCmd']);
        if ($cronCmd !== '') {
            if ($nextExecution === 0) {
                return null;
            }
            // Shortest gap between the upcoming runs, irregular expressions (weekdays only)
            // have different gaps. Counted from the run after the stored next execution,
            // which may come from a CLI with a different timezone.
            try {
                $run = $this->nextCronRun($cronCmd, $nextExecution);
                $gaps = [];
                for ($i = 0; $i < 8; $i++) {
                    $following = $this->nextCronRun($cronCmd, $run);
                    $gaps[] = $following - $run;
                    $run = $following;
                }
                return max(60, min($gaps));
            } catch (\Throwable) {
                return null;
            }
        }
        return $schedule['interval'] > 0 ? $schedule['interval'] : null;
    }

    private function nextCronRun(string $cronCmd, int $after): int
    {
        $cronCommand = new CronCommand($cronCmd, $after);
        $cronCommand->calculateNextValue();
        return (int) $cronCommand->getTimestamp();
    }

    /**
     * Command identifier or short class name. TYPO3 v14 stores it in `tasktype`,
     * v12/v13 only in the serialized task, which is read without unserializing it.
     *
     * @param array<string, mixed> $row
     */
    private function taskName(array $row): string
    {
        $taskType = (string) ($row['tasktype'] ?? '');
        if ($taskType !== '') {
            return str_contains($taskType, '\\') ? substr(strrchr($taskType, '\\'), 1) : $taskType;
        }

        $serializedTask = (string) ($row['serialized_task_object'] ?? '');
        if (preg_match('/commandIdentifier";s:\d+:"([^"]+)"/', $serializedTask, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^O:\d+:"([^"]+)"/', $serializedTask, $matches)) {
            return substr(strrchr('\\' . $matches[1], '\\'), 1);
        }
        return 'unknown';
    }

    /**
     * On shared hosting these values describe the whole server, not the
     * account, so the warnings can be disabled in the extension configuration.
     *
     * @param list<array{key: string, message: string}> $warnings
     * @return array<string, mixed>
     */
    private function collectSystem(array &$warnings): array
    {
        $warn = (bool) $this->extensionSetting('systemWarnings', true);

        $cpuCount = $this->readCpuCount();
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        $loadAverage = $load === false ? null : [
            '1m' => round($load[0], 2),
            '5m' => round($load[1], 2),
            '15m' => round($load[2], 2),
        ];

        if ($warn && $loadAverage !== null && $cpuCount > 0 && $loadAverage['5m'] / $cpuCount >= self::WARN_LOAD_PER_CPU) {
            $warnings[] = $this->warning('system.load_high', sprintf('Load average %.2f on %d CPUs', $loadAverage['5m'], $cpuCount));
        }

        $memory = $this->readMemory();
        if ($warn && ($memory['usedRatio'] ?? 0) >= self::WARN_MEMORY_RATIO) {
            $warnings[] = $this->warning('system.memory_high', sprintf('Memory usage at %d%%', (int) ($memory['usedRatio'] * 100)));
        }

        $disks = $this->readDisks();
        foreach ($disks as $name => $disk) {
            if ($warn && $disk['usedRatio'] >= self::WARN_DISK_RATIO) {
                $warnings[] = $this->warning('system.disk_high.' . $name, sprintf('Disk "%s" at %d%%', $name, (int) ($disk['usedRatio'] * 100)));
            }
        }

        return [
            'cpuCount' => $cpuCount ?: null,
            'loadAverage' => $loadAverage,
            'memory' => $memory,
            'disk' => $disks,
        ];
    }

    private function readCpuCount(): int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo === false) {
            return 0;
        }
        return preg_match_all('/^processor\s*:/m', $cpuinfo);
    }

    /**
     * Without MemAvailable (old kernels, OpenVZ/Virtuozzo containers) it is
     * approximated by free memory plus buffers and page cache.
     *
     * @return array<string, int|float>|null
     */
    private function readMemory(): ?array
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false) {
            return null;
        }

        preg_match_all('/^(\w+):\s+(\d+)\s*kB/m', $meminfo, $matches);
        $values = array_map(
            static fn(string $kb): int => (int) $kb * 1024,
            array_combine($matches[1], $matches[2])
        );

        $total = $values['MemTotal'] ?? 0;
        $available = $values['MemAvailable']
            ?? (isset($values['MemFree']) ? $values['MemFree'] + ($values['Buffers'] ?? 0) + ($values['Cached'] ?? 0) : null);
        if ($total <= 0 || $available === null) {
            return null;
        }
        $swapTotal = $values['SwapTotal'] ?? 0;
        $swapFree = $values['SwapFree'] ?? 0;

        return [
            'totalBytes' => $total,
            'availableBytes' => $available,
            'usedRatio' => round(max(0, 1 - $available / $total), 3),
            'swapTotalBytes' => $swapTotal,
            'swapUsedBytes' => $swapTotal - $swapFree,
            'swapUsedRatio' => $swapTotal > 0 ? round(1 - $swapFree / $swapTotal, 3) : 0,
        ];
    }

    /**
     * All block devices from /proc/mounts, keyed by device name. Mount points
     * are not returned. If none is readable (open_basedir), the volume of the
     * project is reported as "project".
     *
     * @return array<string, array<string, mixed>>
     */
    private function readDisks(): array
    {
        $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        // device => shortest mount point
        $devices = [];
        foreach ($mounts as $line) {
            [$device, $mountPoint] = explode(' ', $line) + [1 => ''];
            // /proc/mounts escapes spaces etc. as octal
            $mountPoint = stripcslashes($mountPoint);
            $isRoot = $mountPoint === '/';
            if (!$isRoot && (!str_starts_with($device, '/dev/') || str_starts_with($device, '/dev/loop'))) {
                continue;
            }
            $name = $isRoot && !str_starts_with($device, '/dev/') ? $device : basename($device);
            if (!isset($devices[$name]) || strlen($mountPoint) < strlen($devices[$name])) {
                $devices[$name] = $mountPoint;
            }
        }

        $disks = [];
        foreach ($devices as $name => $mountPoint) {
            $disk = $this->readDisk($mountPoint);
            if ($disk !== null) {
                $disks[$name] = $disk;
            }
        }

        if ($disks === []) {
            $disk = $this->readDisk(Environment::getProjectPath());
            if ($disk !== null) {
                $disks['project'] = $disk;
            }
        }
        return $disks;
    }

    /**
     * @return array{totalBytes: int, freeBytes: int, usedRatio: float}|null
     */
    private function readDisk(string $path): ?array
    {
        if (!function_exists('disk_total_space') || !function_exists('disk_free_space')) {
            return null;
        }
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        return [
            'totalBytes' => (int) $total,
            'freeBytes' => (int) $free,
            'usedRatio' => round(1 - $free / $total, 3),
        ];
    }

    /**
     * Prometheus drops the body of non-2xx responses, so errors are reported
     * via `typo3_health_status` instead of the HTTP status.
     *
     * @param array<string, mixed> $payload
     */
    private function prometheus(array $payload): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write((new PrometheusFormatter())->format($payload));
        return $response
            ->withHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function json(array $payload, int $status = 200): ResponseInterface
    {
        return (new JsonResponse($payload, $status))
            ->withHeader('Cache-Control', 'no-store');
    }
}
