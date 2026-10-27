# sdk-php

PHP client for the sitewarm API. Models in [`src/Model`](src/Model) are generated from the OpenAPI 3.1 spec in [`openapi/openapi.yaml`](openapi/openapi.yaml) with [OpenAPI Generator](https://openapi-generator.tech); the client (`SiteWarm`) is hand-written on ext-curl. PHP 8.1+. Runtime dependency: `guzzlehttp/psr7` (used by the generated serializer).

**Status: pre-release (no tagged version, not on Packagist).** The API and this client can still change. The spec describes the whole planned surface; the service may not offer every operation yet.

```php
use Sitewarm\Sdk\SiteWarm;
use Sitewarm\Sdk\Model\{SiteCreate, WarmRunCreate};

$c = new SiteWarm(getenv('SITEWARM_API_KEY'), 'https://<api host>/v1');

$site = $c->sites->create(new SiteCreate(['hostname' => 'example.com']));
$run  = $c->warmRuns->create($site->getId(), new WarmRunCreate());
$done = $c->warmRuns->waitFor($run->getId(), timeout: 600, pollInterval: 2);
foreach ($c->warmRuns->results($done->getId(), 'miss') as $r) {
    echo $r->getUrl(), "\n";
}
```

A site must be verified before a run is accepted; verification happens outside this API.

## Surface

- `$c->sites` (`get`, `create`, `all`) and `$c->warmRuns` (`create`, `get`, `cancel`, `results`, `waitFor`).
- Every operation of the spec is reachable with `$c->call('<operationId>', path: [...], query: [...], body: ...)`; `$c->items(...)` iterates list operations as a generator (cursors are opaque). The operation table (`Operations`) is generated and a test checks it against the spec.
- Webhook callbacks are server-to-customer and not covered.

## Behavior

- **Auth**: `Authorization: Bearer <key>`. The key is held in a `Secret` object that has no properties, so it is not in `__toString()`, `var_dump`, `print_r`, `var_export`, `(array)` casts, exception messages or stack traces (the `Transport` header argument is `#[\SensitiveParameter]` and transport errors are rethrown without their frames); the SDK never logs, and the client refuses to be serialized. The base URL must be https (http only for localhost) with no credentials or query. The default `CurlTransport` never follows redirects, so the key never goes to another origin; a 3xx is thrown as `ApiException`. A custom `Transport` is responsible for the same: the SDK cannot stop a transport you supply from following redirects, so it must not.
- **No default base URL**: pass the API host you were given.
- **Retries**: up to 3 (`maxRetries`; a negative value keeps the default) on `429`, `5xx` (not `501`) and network errors, exponential backoff with equal jitter, capped at 30 s. `Retry-After` is honored; if it exceeds the cap the error is thrown instead of sleeping. `429` is always retried; `5xx`, network errors and a response body that breaks off only when repeating is safe (`GET`, `HEAD`, `PUT`, `OPTIONS`, or a POST with an `Idempotency-Key`; never `DELETE`/`PATCH`). A `timeout` <= 0 keeps the default (30 s).
- **Idempotency**: every POST that accepts `Idempotency-Key` sends one (random UUID unless you pass one), generated once per call and reused by its retries, so a retried `warmRuns->create` never starts two runs.
- **Limits**: response bodies over 32 MiB throw `ResponseTooLargeException` (not retried); `items()` throws on a repeated cursor or after 10,000 pages instead of stopping silently; a `limit` query value outside 1..100 throws before the request (`null`, `''` or `0` = server default).
- **Errors**: `ApiException` (`status`, `kind()`, RFC 9457 `problem`, `errorCode`, `retryAfter`, `rateLimitRemaining`), `ConnectionException`, and `WaitTimeoutException` from `waitFor`, which does not cancel the run. All extend `SiteWarmException`.
- Options (third constructor argument): `maxRetries`, `retryBase`, `retryMax`, `timeout`, `userAgent`, and a custom `transport`.

## Development

No local PHP needed; everything runs in the official images:

```
docker run --rm -v "$PWD":/work -w /work composer:2 composer install --ignore-platform-reqs
docker run --rm -v "$PWD":/work -w /work php:8.3-cli vendor/bin/phpunit
```

To regenerate after changing `openapi/openapi.yaml` (needs Python 3 with PyYAML and Docker): `sh scripts/generate.sh`. CI fails if the generated sources are out of date.

## License

Apache-2.0, see [LICENSE](LICENSE).
