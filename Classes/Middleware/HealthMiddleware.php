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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * GET /_api/health: database, PHP-FPM, scheduler, Solr and system metrics for monitoring.
 *
 * - Bearer token via `Authorization` or `X-Authorization`; disabled without token.
 * - 200 for ok/degraded, 503 if the database is unreachable.
 * - `?format=prometheus` returns the Prometheus text format, always with 200.
 * - Counters are cumulative since server start, graph their rate.
 * - Runs before frontend user authentication
 */
class HealthMiddleware implements MiddlewareInterface
{
    private const PATH = '/_api/health';

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
    private const WARN_SCHEDULER_IDLE_SECONDS = 900;
    private const WARN_TASK_OVERDUE_SECONDS = 3600;
    private const WARN_TASK_RUNNING_SECONDS = 3600;
    private const WARN_SOLR_HEAP_RATIO = 0.9;
    private const WARN_SOLR_QUEUE_STALLED_SECONDS = 3600;

    // EXT:solr PingFailedException codes: no HTTP response vs. error response
    private const SOLR_PING_NO_RESPONSE = 1645716101;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (rtrim($request->getUri()->getPath(), '/') !== self::PATH) {
            return $handler->handle($request);
        }

        $expectedToken = (string) getenv('TYPO3_HEALTH_ENDPOINT_BEARER');
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
            'hostname' => gethostname() ?: null,
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

    private function isAuthorized(ServerRequestInterface $request, string $expectedToken): bool
    {
        foreach (['Authorization', 'X-Authorization'] as $header) {
            if (preg_match('/^Bearer\s+(.+)$/i', $request->getHeaderLine($header), $matches)
                && hash_equals($expectedToken, trim($matches[1]))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private function collectDatabase(array &$warnings): array
    {
        $startedAt = microtime(true);

        try {
            $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
            $maxConnections = (int) $connection->executeQuery('SELECT @@max_connections')->fetchOne();

            $placeholders = implode(',', array_fill(0, count(self::DB_STATUS_VARIABLES), '?'));
            $rows = $connection->executeQuery(
                'SHOW GLOBAL STATUS WHERE Variable_name IN (' . $placeholders . ')',
                self::DB_STATUS_VARIABLES
            )->fetchAllAssociative();
        } catch (\Throwable $e) {
            // Message may contain credentials, log only
            $this->logger->error('Health check: database not reachable', ['exception' => $e]);
            $warnings[] = 'Database not reachable';
            return [
                'reachable' => false,
            ];
        }

        $status = [];
        foreach ($rows as $row) {
            $status[(string) $row['Variable_name']] = (int) $row['Value'];
        }

        $threadsConnected = $status['Threads_connected'] ?? 0;
        $connectionRatio = $maxConnections > 0 ? round($threadsConnected / $maxConnections, 3) : null;

        if ($connectionRatio !== null && $connectionRatio >= self::WARN_CONNECTION_RATIO) {
            $warnings[] = sprintf('Database connections at %d%% of max_connections', (int) ($connectionRatio * 100));
        }
        if (($status['Innodb_row_lock_current_waits'] ?? 0) >= self::WARN_ROW_LOCK_WAITS) {
            $warnings[] = sprintf('%d InnoDB row lock waits pending', $status['Innodb_row_lock_current_waits']);
        }

        return [
            'reachable' => true,
            'latencyMs' => round((microtime(true) - $startedAt) * 1000, 1),
            'maxConnections' => $maxConnections,
            'connectionRatio' => $connectionRatio,
            'status' => $status,
        ];
    }

    /**
     * Listen queue > 0 means all workers are busy.
     *
     * @param list<string> $warnings
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
            $warnings[] = sprintf('%d requests waiting for a PHP-FPM worker', $fpm['listenQueue']);
        }

        return $fpm;
    }

    /**
     * Last cron run and state of all enabled tasks. Failure messages are
     * omitted, they may contain paths.
     *
     * @param list<string> $warnings
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
                    ['uid', 'description', 'nextexecution', 'lastexecution_time', 'lastexecution_failure', 'serialized_executions', 'serialized_task_object'],
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

        if ($lastRunEnd === 0 || $now - $lastRunEnd > self::WARN_SCHEDULER_IDLE_SECONDS) {
            $warnings[] = 'Scheduler has not run for more than ' . (self::WARN_SCHEDULER_IDLE_SECONDS / 60) . ' minutes';
        }

        $tasks = [];
        foreach ($rows as $row) {
            $executions = $row['serialized_executions'] ? unserialize((string) $row['serialized_executions'], ['allowed_classes' => false]) : [];
            $runningSince = is_array($executions) && $executions !== [] ? (int) min($executions) : null;
            $failed = trim((string) $row['lastexecution_failure']) !== '';
            $nextExecution = (int) $row['nextexecution'];
            $overdue = $runningSince === null && $nextExecution > 0 && $now - $nextExecution > self::WARN_TASK_OVERDUE_SECONDS;
            $name = $this->taskName((string) $row['serialized_task_object']);

            if ($failed) {
                $warnings[] = sprintf('Task %d (%s) failed', $row['uid'], $name);
            }
            if ($runningSince !== null && $now - $runningSince > self::WARN_TASK_RUNNING_SECONDS) {
                $warnings[] = sprintf('Task %d (%s) running for %d minutes', $row['uid'], $name, intdiv($now - $runningSince, 60));
            }

            $tasks[] = [
                'uid' => (int) $row['uid'],
                'name' => $name,
                'description' => (string) $row['description'],
                'lastExecution' => $row['lastexecution_time'] ? gmdate('c', (int) $row['lastexecution_time']) : null,
                'nextExecution' => $nextExecution ? gmdate('c', $nextExecution) : null,
                'runningSince' => $runningSince ? gmdate('c', $runningSince) : null,
                'failed' => $failed,
                'overdue' => $overdue,
            ];
        }

        $overdueCount = count(array_filter($tasks, static fn(array $task): bool => $task['overdue']));
        if ($overdueCount > 0) {
            $warnings[] = sprintf('%d scheduler tasks overdue', $overdueCount);
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
     * @param list<string> $warnings
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
            $warnings[] = 'Solr status not readable';
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
     * @param list<string> $warnings
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
                    $warnings[] = sprintf('Solr core %s not reachable', $coreName);
                }
                $cores[$server . '/' . $coreName] = $core;
            }
        }

        foreach ($servers as $info) {
            if (($info['heapRatio'] ?? 0) >= self::WARN_SOLR_HEAP_RATIO) {
                $warnings[] = sprintf('Solr heap at %d%%', (int) ($info['heapRatio'] * 100));
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
     * @param list<string> $warnings
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
            $warnings[] = sprintf('Solr index queue stalled for %d minutes', intdiv($oldestPendingAge, 60));
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
     * Command identifier or short class name, read without unserializing the task.
     */
    private function taskName(string $serializedTask): string
    {
        if (preg_match('/commandIdentifier";s:\d+:"([^"]+)"/', $serializedTask, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^O:\d+:"([^"]+)"/', $serializedTask, $matches)) {
            return substr(strrchr('\\' . $matches[1], '\\'), 1);
        }
        return 'unknown';
    }

    /**
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private function collectSystem(array &$warnings): array
    {
        $cpuCount = $this->readCpuCount();
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        $loadAverage = $load === false ? null : [
            '1m' => round($load[0], 2),
            '5m' => round($load[1], 2),
            '15m' => round($load[2], 2),
        ];

        if ($loadAverage !== null && $cpuCount > 0 && $loadAverage['5m'] / $cpuCount >= self::WARN_LOAD_PER_CPU) {
            $warnings[] = sprintf('Load average %.2f on %d CPUs', $loadAverage['5m'], $cpuCount);
        }

        $memory = $this->readMemory();
        if (($memory['usedRatio'] ?? 0) >= self::WARN_MEMORY_RATIO) {
            $warnings[] = sprintf('Memory usage at %d%%', (int) ($memory['usedRatio'] * 100));
        }

        $disks = $this->readDisks();
        foreach ($disks as $name => $disk) {
            if ($disk['usedRatio'] >= self::WARN_DISK_RATIO) {
                $warnings[] = sprintf('Disk "%s" at %d%%', $name, (int) ($disk['usedRatio'] * 100));
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
        $available = $values['MemAvailable'] ?? 0;
        $swapTotal = $values['SwapTotal'] ?? 0;
        $swapFree = $values['SwapFree'] ?? 0;

        return [
            'totalBytes' => $total,
            'availableBytes' => $available,
            'usedRatio' => $total > 0 ? round(1 - $available / $total, 3) : 0,
            'swapTotalBytes' => $swapTotal,
            'swapUsedBytes' => $swapTotal - $swapFree,
            'swapUsedRatio' => $swapTotal > 0 ? round(1 - $swapFree / $swapTotal, 3) : 0,
        ];
    }

    /**
     * All block devices from /proc/mounts, keyed by device name. Mount points
     * are not returned.
     *
     * @return array<string, array<string, mixed>>
     */
    private function readDisks(): array
    {
        $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($mounts === false) {
            return [];
        }

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
        return $disks;
    }

    /**
     * @return array{totalBytes: int, freeBytes: int, usedRatio: float}|null
     */
    private function readDisk(string $path): ?array
    {
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
