# lin_typo3_health

`GET /_api/health` returns database, PHP-FPM, scheduler and system metrics as JSON.

## Setup

Set a long random token in the environment, e.g. `openssl rand -hex 32`:

```
TYPO3_HEALTH_ENDPOINT_BEARER=...
```

Without it the endpoint is disabled.

## Usage

```
curl -H "Authorization: Bearer $TOKEN" https://example.org/_api/health
```

`X-Authorization` works too, for servers that strip `Authorization`.

- `200` with `status` `ok` or `degraded` (see `warnings`)
- `503` with `status` `error` if the database is unreachable
- `401` on missing or wrong token

## Prometheus

```
curl -H "Authorization: Bearer $TOKEN" "https://example.org/_api/health?format=prometheus"
```

Same data in the Prometheus text format, metrics prefixed with `typo3_health_`. Always `200`: Prometheus discards non-2xx bodies, so the state is in `typo3_health_status` (0 ok, 1 degraded, 2 error) and `typo3_health_database_up`. Warnings come as `typo3_health_warning{message="…"}`, scheduler tasks as `typo3_health_scheduler_task_*{uid="…"}`.

## Solr

With EXT:solr installed, `solr` lists every core of every site (ping, documents), JVM heap and version per Solr server, and the index queue (pending, failed, age of the oldest pending item). Without EXT:solr it is `null` and no `typo3_health_solr_*` metrics are written.

Warnings: core not reachable, heap at 90 %, oldest pending queue item older than 60 minutes. Failed queue items are only counted, they tend to stay and would warn permanently. Once a Solr server gives no response at all, its remaining cores are not pinged, so a dead server costs one connect timeout (`HTTP.connect_timeout`).

Counters (`Connection_errors_max_connections`, `maxChildrenReached`, `slowRequests`, …) are cumulative since server start; graph their rate.
