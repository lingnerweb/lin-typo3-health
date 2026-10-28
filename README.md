# lin_typo3_health

Monitoring endpoint for TYPO3: `GET /_api/health` reports system metrics, database, PHP-FPM, scheduler and Solr.

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

Default format. Top-level keys: `status`, `warnings`, `hostname`, `time`, `typo3`, `php`, `database`, `fpm`, `scheduler`, `solr`, `system`, `durationMs`. Sections that are not available (no PHP-FPM, no EXT:solr, database down) are `null`.

- `200` with `status` `ok` or `degraded`
- `503` with `status` `error`

### Prometheus

```
curl -H "Authorization: Bearer $TOKEN" "https://example.org/_api/health?format=prometheus"
```

Text exposition format, metrics prefixed with `typo3_health_`. Always `200`: Prometheus discards the body of non-2xx responses, so the state is in the metrics:

- `typo3_health_status`: 0 ok, 1 degraded, 2 error
- `typo3_health_database_up`
- `typo3_health_warning{message="…"}`: one series per active warning
- `typo3_health_info{typo3_version, php_version, …}`: versions as labels
- `typo3_health_scheduler_task_*{uid="…"}`: one series per task, including `typo3_health_scheduler_task_interval_seconds`
- `typo3_health_database_connection_ratio{limit="…"}`: `max_user_connections` or `max_connections`

## Extensions

### Solr

Active when EXT:solr is installed, otherwise `solr` is `null` and no `typo3_health_solr_*` metrics are written. Reports:

- every core of every site: reachable, ping time, number of documents
- per Solr server: version and JVM heap
- the index queue: pending and failed items, age of the oldest pending item

Warnings: a core is not reachable, the heap is at 90 %, the oldest pending queue item is older than 60 minutes. Failed queue items are only counted, they tend to stay and would warn permanently.

Once a Solr server gives no response at all, its remaining cores are not pinged, so a dead server costs one connect timeout (`HTTP.connect_timeout`) instead of one per core.
