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

Without the token the endpoint is disabled and the request is passed on to TYPO3.

## Usage

```
curl -H "Authorization: Bearer $TOKEN" https://example.org/_api/health
```

`X-Authorization` works too, for servers that strip `Authorization` or sit behind basic auth. A missing or wrong token returns `401`.

The endpoint runs before frontend user authentication and site resolution, so it stays cheap and works on every domain of the installation.

The overall `status` is `ok`, `degraded` as soon as there is a warning, or `error` if the database is unreachable. Warnings:

| Check | Warning when |
|---|---|
| Database | unreachable, connections at 80 % of `max_connections`, 5 or more InnoDB row lock waits |
| PHP-FPM | requests waiting in the listen queue |
| Scheduler | no run for 15 minutes, a task failed, a task overdue or running for more than an hour |
| System | 5-minute load above 2 per CPU, memory or a disk at 90 % |

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
- `typo3_health_scheduler_task_*{uid="…"}`: one series per task

## Extensions

### Solr

Active when EXT:solr is installed, otherwise `solr` is `null` and no `typo3_health_solr_*` metrics are written. Reports:

- every core of every site: reachable, ping time, number of documents
- per Solr server: version and JVM heap
- the index queue: pending and failed items, age of the oldest pending item

Warnings: a core is not reachable, the heap is at 90 %, the oldest pending queue item is older than 60 minutes. Failed queue items are only counted, they tend to stay and would warn permanently.

Once a Solr server gives no response at all, its remaining cores are not pinged, so a dead server costs one connect timeout (`HTTP.connect_timeout`) instead of one per core.
