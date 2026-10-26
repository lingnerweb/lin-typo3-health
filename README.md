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

Counters (`Connection_errors_max_connections`, `maxChildrenReached`, `slowRequests`, …) are cumulative since server start; graph their rate.
