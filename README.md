# lin_typo3_health

Monitoring endpoint for TYPO3: `GET /_api/health` reports system metrics, database, PHP-FPM, scheduler, Solr and errors from the TYPO3 logs.

## Setup

Add the repository and require the extension:

```json
"repositories": [
	{ "type": "git", "url": "git@github.com:lingnerweb/lin-typo3-health.git" }
]
```

```
composer require lingner/lin-typo3-health:dev-main
```

Set a long random token in the environment, e.g. `openssl rand -hex 32`:

```
TYPO3_HEALTH_ENDPOINT_BEARER=...
```

Where the environment cannot be set (shared hosting), either use `SetEnv TYPO3_HEALTH_ENDPOINT_BEARER ...` in the `.htaccess` (the `REDIRECT_` prefix that CGI/FastCGI add after the rewrite is handled) or the `token` in the extension configuration (Admin Tools → Settings → Extension Configuration). The extension configuration is stored in `config/system/settings.php`, keep that file out of public repositories then. The environment wins over the extension configuration.

Without the token the endpoint is disabled and the request is passed on to TYPO3.

## Usage

```
curl -H "Authorization: Bearer $TOKEN" https://example.org/_api/health
```

`X-Authorization` works too, for servers that strip `Authorization` or sit behind basic auth. Under CGI/FastCGI the header restored by the TYPO3 `.htaccess` (`REDIRECT_HTTP_AUTHORIZATION`) is read as well. A missing or wrong token returns `401`.

The endpoint runs before frontend user authentication and site resolution, so it stays cheap and works on every domain of the installation.

The overall `status` is `ok`, `degraded` as soon as there is a warning, or `error` if the database is unreachable. Warnings:

| Check | Warning when |
|---|---|
| Database | unreachable, connections at 80 % of the limit (see below), 5 or more InnoDB row lock waits |
| PHP-FPM | requests waiting in the listen queue |
| Scheduler | no run for 150 % of the shortest task interval, a task failed, a task overdue or running for more than 150 % of its interval |
| System | 5-minute load above 2 per CPU, memory or a disk at 90 % (can be disabled) |

The scheduler thresholds follow the configured intervals (fixed interval or cron expression, for irregular expressions the shortest gap). A task configured every 5 minutes on a host whose cron only runs every 15 minutes warns, because it cannot run as configured. Single-run tasks are not checked, without any recurring task there is no idle warning.

The database ratio refers to the own connections when the account has a `max_user_connections` limit, otherwise to `Threads_connected` of the whole server relative to `max_connections` (`connectionLimit` names which one). MySQL and MariaDB are supported. Only `SELECT 1` decides whether the database is reachable, status queries the host denies are left empty.

## Configuration

Admin Tools → Settings → Extension Configuration → `lin_typo3_health`:

- `token`: bearer token when the environment variable is not set
- `systemWarnings`: warn on load, memory and disk usage (default on)

## Shared hosting

Works with PHP-FPM, FastCGI/CGI and LiteSpeed, with `open_basedir` and `disable_functions`. Values that cannot be read are `null` (JSON) or missing (Prometheus):

- `fpm` is only available under PHP-FPM.
- With `open_basedir`, `/proc` is not readable: no CPU count and memory, and instead of all block devices the volume of the project is reported as disk `project`.
- Load, memory and disk describe the whole server, not the account. Disable `systemWarnings` if they are not actionable; the metrics are still reported.
- Without `MemAvailable` (OpenVZ/Virtuozzo) available memory is approximated by free memory, buffers and cache.

## Formatting

Both formats carry the same data. Counters (`Slow_queries`, `Connection_errors_max_connections`, `maxChildrenReached`, `slowRequests`, …) are cumulative since server start, graph their rate.

### JSON

Default format. Top-level keys: `status`, `warnings`, `hostname`, `time`, `typo3`, `php`, `database`, `fpm`, `scheduler`, `solr`, `sysLog`, `errorLog`, `system`, `durationMs`. Sections that are not available (no PHP-FPM, no EXT:solr, database down, no log file) are `null`.

Each warning is an object with `key` and `message`. The key follows the message as `<area>.<condition>[.<subject>]` and stays the same while the warning lasts, the message carries the current values:

| Key | Message |
|---|---|
| `database.not_reachable` | Database not reachable |
| `database.connections_high` | Database connections at 85% of max_user_connections |
| `database.row_lock_waits_pending` | 7 InnoDB row lock waits pending |
| `fpm.requests_waiting` | 3 requests waiting for a PHP-FPM worker |
| `scheduler.never_run` | Scheduler has never run |
| `scheduler.not_running` | Scheduler has not run for 42 minutes, shortest task interval is 5 minutes |
| `scheduler.tasks_overdue` | 2 scheduler tasks overdue |
| `scheduler.task_failed.<uid>` | Task 12 (IndexQueueWorkerTask) failed |
| `scheduler.task_running_long.<uid>` | Task 12 (IndexQueueWorkerTask) running for 95 minutes (1 hour 35 minutes) |
| `solr.status_not_readable` | Solr status not readable |
| `solr.core_not_reachable.<core>` | Solr core core_de not reachable |
| `solr.heap_high.<server>` | Solr heap at 93% |
| `solr.index_queue_stalled` | Solr index queue stalled for 1344 minutes (22 hours 24 minutes) |
| `system.load_high` | Load average 9.12 on 4 CPUs |
| `system.memory_high` | Memory usage at 91% |
| `system.disk_high.<name>` | Disk "project" at 92% |

Use the key to follow a warning across checks. Keys and messages contain no hosts: Solr servers are numbered like the `server` label of the Prometheus metrics. Durations from one hour on are broken down into days, hours and minutes.

- `200` with `status` `ok` or `degraded`
- `503` with `status` `error`

### Prometheus

```
curl -H "Authorization: Bearer $TOKEN" "https://example.org/_api/health?format=prometheus"
```

Text exposition format, metrics prefixed with `typo3_health_`. Always `200`: Prometheus discards the body of non-2xx responses, so the state is in the metrics:

- `typo3_health_status`: 0 ok, 1 degraded, 2 error
- `typo3_health_database_up`
- `typo3_health_warning{key="…", message="…"}`: one series per active warning. The message changes with its values, so group and alert by `key`, e.g. `max without (message) (typo3_health_warning)`
- `typo3_health_info{typo3_version, php_version, …}`: versions as labels
- `typo3_health_scheduler_task_*{uid="…"}`: one series per task, including `typo3_health_scheduler_task_interval_seconds`
- `typo3_health_database_connection_ratio{limit="…"}`: `max_user_connections` or `max_connections`
- `typo3_health_sys_log_errors{type="user|system|security"}`, `typo3_health_log_entries{log, level}`, `typo3_health_log_error_pages{type="page|content"}`: counts of the last 24 hours, see [Logs](#logs)

## Extensions

### Solr

Active when EXT:solr is installed, otherwise `solr` is `null` and no `typo3_health_solr_*` metrics are written. Reports:

- every core of every site: reachable, ping time, number of documents
- per Solr server: version and JVM heap
- the index queue of the configured Solr sites: pending and failed items, age of the oldest pending item

Warnings: a core is not reachable, the heap is at 90 %, the oldest pending queue item is older than 60 minutes. Failed queue items are only counted, they tend to stay and would warn permanently. Pending means what the index queue worker picks up: no error, changed after the last indexing, not scheduled for later. Items of sites that are no longer configured are not counted, the worker never indexes them.

Once a Solr server gives no response at all, its remaining cores are not pinged, so a dead server costs one connect timeout (`HTTP.connect_timeout`) instead of one per core.

## Logs

Both are counted over the last 24 hours and raise no warning, alert on them in the monitoring.

### Backend log (`sysLog`)

Entries of `sys_log` (Backend → System → Log) with an error classification, by type: `user`, `system`, `security`. Counts only, the details contain IP addresses and record titles.

### Log files (`errorLog`)

Entries of level error and above in `var/log/typo3_*.log` (the default `FileWriter` files, deprecation logs excluded), by file (`typo3`, `typo3_solr`, …) and level. Error pages are counted separately:

- `page`: an uncaught exception in a web request, the visitor or editor got an error page instead of the page
- `content`: a content element replaced by "Oops, an error occurred!", the rest of the page rendered

TYPO3 does not log 404 and 403, they are not counted.

Each file is read backwards from its end, at most 8 MiB per file, so large logs stay cheap. When that limit cuts the 24 hours short, `complete` is `false` (`typo3_health_log_complete` 0) and the counts cover less time.

TYPO3 writes messages with their line breaks. A message that carries visitor input can therefore contain lines that look like entries. Reading does not stop at the first entry older than the window, so a forged old date cannot hide the real entries before it; forged entries can still be counted. Treat the counts as an indicator, not as proof. Logs written elsewhere (custom writer configuration, syslog, rotated files) are not read.

### Log entries (`/_api/health/logs`)

The entries behind the log file counts, for importing them into a log store such as Loki. Same token as `/_api/health`, JSON only:

```
curl -H "Authorization: Bearer $TOKEN" "https://example.org/_api/health/logs?since=1793180401"
```

- `since`: Unix timestamp, entries from this second on. Default: the last 24 hours.
- `entries`: oldest first, at most 500 per response. Each has `time`, `timestamp`, `log`, `level`, `component`, `requestId`, `exception` (short class), `errorPage` (`page`, `content` or `null`) and `message`.
- `more`: there are further entries, call again with `since` set to `next`. The entries of that second come again, drop identical ones (Loki does this by itself). One request can log several entries in the same second, so request id and time alone are not unique.
- `complete`: `false` when the 8 MiB read limit per file was reached before `since`, older entries are then not available.

Messages are cleaned: the appended data (`- {json}`, which holds stack traces and request details) and stack traces are cut, a repeated exception message is dropped, query strings are replaced by `<query_stripped>` (`https://example.org/api<query_stripped>`, they carry tokens and keys), credentials in URLs by `[credentials]`, IP addresses, email addresses and paths outside the project by `[ip]`, `[email]` and `[path]`, project paths are made relative, the length is limited to 500 characters. Find the full entry with the request id in the log file.
