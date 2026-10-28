<?php

declare(strict_types=1);

namespace LINGNER\LinTypo3Health\Formatter;

/**
 * Renders the health payload in the Prometheus text exposition format.
 *
 * Metric names are stable, dashboards and alert rules depend on them.
 * The scraper adds the project label, so none is set here.
 */
class PrometheusFormatter
{
    private const PREFIX = 'typo3_health_';

    private const STATUS_VALUES = [
        'ok' => 0,
        'degraded' => 1,
        'error' => 2,
    ];

    /**
     * MySQL status variable => [metric name, type, help]
     */
    private const DB_STATUS_METRICS = [
        'Threads_connected' => ['database_threads_connected', 'gauge', 'Open database connections.'],
        'Threads_running' => ['database_threads_running', 'gauge', 'Database connections currently executing a query.'],
        'Max_used_connections' => ['database_max_used_connections', 'gauge', 'Peak of simultaneous connections since server start.'],
        'Connection_errors_max_connections' => ['database_connection_errors_max_connections_total', 'counter', 'Connections refused because max_connections was reached.'],
        'Aborted_connects' => ['database_aborted_connects_total', 'counter', 'Failed connection attempts.'],
        'Innodb_row_lock_current_waits' => ['database_innodb_row_lock_current_waits', 'gauge', 'Queries currently waiting for an InnoDB row lock.'],
        'Slow_queries' => ['database_slow_queries_total', 'counter', 'Queries slower than long_query_time.'],
        'Questions' => ['database_questions_total', 'counter', 'Statements sent by clients.'],
        'Uptime' => ['database_uptime_seconds', 'gauge', 'Database server uptime.'],
    ];

    /**
     * FPM payload key => [metric name, type, help]
     */
    private const FPM_METRICS = [
        'startSince' => ['fpm_uptime_seconds', 'gauge', 'Seconds since the FPM pool started.'],
        'acceptedConnections' => ['fpm_accepted_connections_total', 'counter', 'Requests accepted by the pool.'],
        'listenQueue' => ['fpm_listen_queue', 'gauge', 'Requests waiting for a free worker.'],
        'maxListenQueue' => ['fpm_max_listen_queue', 'gauge', 'Peak of the listen queue since pool start.'],
        'listenQueueLength' => ['fpm_listen_queue_length', 'gauge', 'Size of the listen queue.'],
        'activeProcesses' => ['fpm_active_processes', 'gauge', 'Workers handling a request.'],
        'idleProcesses' => ['fpm_idle_processes', 'gauge', 'Workers waiting for a request.'],
        'totalProcesses' => ['fpm_total_processes', 'gauge', 'Workers in the pool.'],
        'maxActiveProcesses' => ['fpm_max_active_processes', 'gauge', 'Peak of active workers since pool start.'],
        'maxChildrenReached' => ['fpm_max_children_reached_total', 'counter', 'Times pm.max_children was reached.'],
        'slowRequests' => ['fpm_slow_requests_total', 'counter', 'Requests slower than request_slowlog_timeout.'],
    ];

    /** @var list<string> */
    private array $lines = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function format(array $payload): string
    {
        $this->lines = [];

        $this->addGeneral($payload);
        $this->addDatabase($payload['database']);
        if ($payload['fpm'] !== null) {
            $this->addFpm($payload['fpm']);
        }
        if ($payload['scheduler'] !== null) {
            $this->addScheduler($payload['scheduler']);
        }
        if ($payload['solr'] !== null) {
            $this->addSolr($payload['solr']);
        }
        $this->addSystem($payload['system']);

        return implode("\n", $this->lines) . "\n";
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addGeneral(array $payload): void
    {
        $this->metric('status', 'gauge', 'Overall status: 0 ok, 1 degraded, 2 error.', [
            [[], self::STATUS_VALUES[$payload['status']]],
        ]);
        $this->metric('warnings', 'gauge', 'Number of active warnings.', [
            [[], count($payload['warnings'])],
        ]);
        $this->metric(
            'warning',
            'gauge',
            'One series per active warning, the message is the label.',
            array_map(static fn(string $warning): array => [['message' => $warning], 1], $payload['warnings'])
        );
        $this->metric('info', 'gauge', 'Server information, the value is always 1.', [
            [[
                'hostname' => (string) $payload['hostname'],
                'typo3_version' => $payload['typo3']['version'],
                'php_version' => $payload['php']['version'],
                'php_sapi' => $payload['php']['sapi'],
                'php_memory_limit' => (string) $payload['php']['memoryLimit'],
            ], 1],
        ]);
        $this->metric('php_max_execution_time_seconds', 'gauge', 'PHP max_execution_time, 0 is unlimited.', [
            [[], $payload['php']['maxExecutionTime']],
        ]);
        $this->metric('duration_seconds', 'gauge', 'Time spent collecting the health data.', [
            [[], $payload['durationMs'] / 1000],
        ]);
    }

    /**
     * @param array<string, mixed> $database
     */
    private function addDatabase(array $database): void
    {
        $this->metric('database_up', 'gauge', 'Whether the database is reachable.', [
            [[], $database['reachable'] ? 1 : 0],
        ]);
        if (!$database['reachable']) {
            return;
        }

        $this->metric('database_latency_seconds', 'gauge', 'Time for connecting and reading the status variables.', [
            [[], $database['latencyMs'] / 1000],
        ]);
        if ($database['maxConnections'] !== null) {
            $this->metric('database_max_connections', 'gauge', 'Configured max_connections.', [
                [[], $database['maxConnections']],
            ]);
        }
        if ($database['maxUserConnections'] !== null) {
            $this->metric('database_max_user_connections', 'gauge', 'Connection limit of the database account, 0 is unlimited.', [
                [[], $database['maxUserConnections']],
            ]);
        }
        if ($database['userConnections'] !== null) {
            $this->metric('database_user_connections', 'gauge', 'Open connections of the database account.', [
                [[], $database['userConnections']],
            ]);
        }
        if ($database['connectionRatio'] !== null) {
            $this->metric('database_connection_ratio', 'gauge', 'Open connections relative to the limit, see the limit label.', [
                [['limit' => (string) $database['connectionLimit']], $database['connectionRatio']],
            ]);
        }

        foreach (self::DB_STATUS_METRICS as $variable => [$name, $type, $help]) {
            if (isset($database['status'][$variable])) {
                $this->metric($name, $type, $help, [[[], $database['status'][$variable]]]);
            }
        }
    }

    /**
     * @param array<string, int|string> $fpm
     */
    private function addFpm(array $fpm): void
    {
        $labels = ['pool' => (string) $fpm['pool']];
        foreach (self::FPM_METRICS as $key => [$name, $type, $help]) {
            $this->metric($name, $type, $help, [[$labels, $fpm[$key]]]);
        }
    }

    /**
     * Every task gets all series, a missing timestamp is 0, so the
     * dashboard table can join them by uid.
     *
     * @param array<string, mixed> $scheduler
     */
    private function addScheduler(array $scheduler): void
    {
        $this->metric('scheduler_last_run_timestamp_seconds', 'gauge', 'End of the last scheduler run, 0 if never.', [
            [[], $this->timestamp($scheduler['lastRun'])],
        ]);

        $series = [
            'info' => [],
            'interval' => [],
            'lastExecution' => [],
            'nextExecution' => [],
            'runningSince' => [],
            'failed' => [],
            'overdue' => [],
        ];
        foreach ($scheduler['tasks'] as $task) {
            $uid = ['uid' => (string) $task['uid']];
            $series['info'][] = [$uid + ['name' => $task['name'], 'description' => $task['description']], 1];
            $series['interval'][] = [$uid, $task['intervalSeconds'] ?? 0];
            $series['lastExecution'][] = [$uid, $this->timestamp($task['lastExecution'])];
            $series['nextExecution'][] = [$uid, $this->timestamp($task['nextExecution'])];
            $series['runningSince'][] = [$uid, $this->timestamp($task['runningSince'])];
            $series['failed'][] = [$uid, $task['failed'] ? 1 : 0];
            $series['overdue'][] = [$uid, $task['overdue'] ? 1 : 0];
        }

        $this->metric('scheduler_task_info', 'gauge', 'Enabled scheduler tasks, the value is always 1.', $series['info']);
        $this->metric('scheduler_task_interval_seconds', 'gauge', 'Configured seconds between two runs, 0 for single-run tasks.', $series['interval']);
        $this->metric('scheduler_task_last_execution_timestamp_seconds', 'gauge', 'Start of the last execution, 0 if never.', $series['lastExecution']);
        $this->metric('scheduler_task_next_execution_timestamp_seconds', 'gauge', 'Next planned execution, 0 if none.', $series['nextExecution']);
        $this->metric('scheduler_task_running_since_timestamp_seconds', 'gauge', 'Start of the current execution, 0 if not running.', $series['runningSince']);
        $this->metric('scheduler_task_failed', 'gauge', 'Whether the last execution failed.', $series['failed']);
        $this->metric('scheduler_task_overdue', 'gauge', 'Whether the task is overdue by more than 150 % of its interval.', $series['overdue']);
    }

    /**
     * Server hosts are not exposed, only the version.
     *
     * @param array<string, mixed> $solr
     */
    private function addSolr(array $solr): void
    {
        $series = ['up' => [], 'ping' => [], 'documents' => []];
        foreach ($solr['cores'] as $core) {
            $labels = ['core' => $core['core']];
            $series['up'][] = [$labels + ['language' => $core['language']], $core['reachable'] ? 1 : 0];
            if ($core['pingMs'] !== null) {
                $series['ping'][] = [$labels, $core['pingMs'] / 1000];
            }
            if ($core['documents'] !== null) {
                $series['documents'][] = [$labels, $core['documents']];
            }
        }
        $this->metric('solr_core_up', 'gauge', 'Whether the Solr core answers the ping.', $series['up']);
        $this->metric('solr_core_ping_seconds', 'gauge', 'Round trip time of the Solr ping.', $series['ping']);
        $this->metric('solr_core_documents', 'gauge', 'Documents in the Solr core.', $series['documents']);

        $servers = ['info' => [], 'used' => [], 'max' => [], 'ratio' => []];
        foreach ($solr['servers'] as $index => $server) {
            $labels = ['server' => (string) $index];
            $servers['info'][] = [$labels + ['version' => $server['version']], 1];
            $servers['used'][] = [$labels, $server['heapUsedBytes']];
            $servers['max'][] = [$labels, $server['heapMaxBytes']];
            if ($server['heapRatio'] !== null) {
                $servers['ratio'][] = [$labels, $server['heapRatio']];
            }
        }
        $this->metric('solr_info', 'gauge', 'Solr server information, the value is always 1.', $servers['info']);
        $this->metric('solr_heap_used_bytes', 'gauge', 'Used JVM heap of the Solr server.', $servers['used']);
        $this->metric('solr_heap_max_bytes', 'gauge', 'Maximum JVM heap of the Solr server.', $servers['max']);
        $this->metric('solr_heap_used_ratio', 'gauge', 'Used JVM heap relative to maximum.', $servers['ratio']);

        $queue = $solr['indexQueue'];
        $this->metric('solr_index_queue_size', 'gauge', 'Items in the Solr index queue.', [[[], $queue['total']]]);
        $this->metric('solr_index_queue_items', 'gauge', 'Index queue items by state.', [
            [['state' => 'pending'], $queue['pending']],
            [['state' => 'failed'], $queue['failed']],
        ]);
        $this->metric('solr_index_queue_oldest_pending_seconds', 'gauge', 'Age of the oldest pending index queue item, 0 if none.', [
            [[], $queue['oldestPendingSeconds'] ?? 0],
        ]);
    }

    /**
     * @param array<string, mixed> $system
     */
    private function addSystem(array $system): void
    {
        if ($system['cpuCount'] !== null) {
            $this->metric('system_cpus', 'gauge', 'Number of CPUs.', [[[], $system['cpuCount']]]);
        }
        if ($system['loadAverage'] !== null) {
            $this->metric('system_load_average', 'gauge', 'System load average.', array_map(
                static fn(string $period, float $value): array => [['period' => $period], $value],
                array_keys($system['loadAverage']),
                $system['loadAverage']
            ));
        }

        $memory = $system['memory'];
        if ($memory !== null) {
            $this->metric('system_memory_total_bytes', 'gauge', 'Total memory.', [[[], $memory['totalBytes']]]);
            $this->metric('system_memory_available_bytes', 'gauge', 'Memory available for new processes.', [[[], $memory['availableBytes']]]);
            $this->metric('system_memory_used_ratio', 'gauge', 'Used memory relative to total.', [[[], $memory['usedRatio']]]);
            $this->metric('system_swap_total_bytes', 'gauge', 'Total swap.', [[[], $memory['swapTotalBytes']]]);
            $this->metric('system_swap_used_bytes', 'gauge', 'Used swap.', [[[], $memory['swapUsedBytes']]]);
            $this->metric('system_swap_used_ratio', 'gauge', 'Used swap relative to total.', [[[], $memory['swapUsedRatio']]]);
        }

        $disks = ['total' => [], 'free' => [], 'ratio' => []];
        foreach ($system['disk'] as $device => $disk) {
            $labels = ['device' => (string) $device];
            $disks['total'][] = [$labels, $disk['totalBytes']];
            $disks['free'][] = [$labels, $disk['freeBytes']];
            $disks['ratio'][] = [$labels, $disk['usedRatio']];
        }
        $this->metric('system_disk_total_bytes', 'gauge', 'Disk size.', $disks['total']);
        $this->metric('system_disk_free_bytes', 'gauge', 'Free disk space.', $disks['free']);
        $this->metric('system_disk_used_ratio', 'gauge', 'Used disk space relative to size.', $disks['ratio']);
    }

    /**
     * @param list<array{0: array<string, string>, 1: int|float}> $samples
     */
    private function metric(string $name, string $type, string $help, array $samples): void
    {
        if ($samples === []) {
            return;
        }

        $name = self::PREFIX . $name;
        $this->lines[] = '# HELP ' . $name . ' ' . $help;
        $this->lines[] = '# TYPE ' . $name . ' ' . $type;
        foreach ($samples as [$labels, $value]) {
            $this->lines[] = $name . $this->labels($labels) . ' ' . $value;
        }
    }

    /**
     * @param array<string, string> $labels
     */
    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];
        foreach ($labels as $key => $value) {
            $pairs[] = $key . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value) . '"';
        }
        return '{' . implode(',', $pairs) . '}';
    }

    private function timestamp(?string $date): int
    {
        return $date === null ? 0 : (int) strtotime($date);
    }
}
