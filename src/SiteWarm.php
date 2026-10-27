<?php

declare(strict_types=1);

namespace Sitewarm\Sdk;

use Sitewarm\Sdk\Model\Result;
use Sitewarm\Sdk\Model\Site;
use Sitewarm\Sdk\Model\SiteCreate;
use Sitewarm\Sdk\Model\WarmRun;
use Sitewarm\Sdk\Model\WarmRunCreate;

/**
 * Client for the sitewarm API (pre-release). Every operation of the spec is reachable by its operationId through
 * {@see call()}; {@see $sites} and {@see $warmRuns} are typed conveniences.
 */
final class SiteWarm
{
    /** SDK version, sent in the User-Agent (no version is declared in composer.json; this matches the other SDKs' preview marker). */
    public const VERSION = '0.1.0-preview';
    public const DEFAULT_USER_AGENT = 'sitewarm-php/' . self::VERSION . ' (+https://github.com/sitewarm/sdk-php)';

    public readonly Sites $sites;
    public readonly WarmRuns $warmRuns;

    private readonly string $baseUrl;
    /** Held in an object without properties, so var_export, print_r, (array) and var_dump never show the key. */
    private readonly Secret $apiKey;
    private readonly Transport $transport;
    /** @var callable(float): void */
    private $sleep;
    /** @var callable(): float */
    private $clock;

    /**
     * @param array{maxRetries?: int, retryBase?: float, retryMax?: float, timeout?: float, userAgent?: string,
     *     transport?: Transport, sleep?: callable(float): void, clock?: callable(): float} $options
     *     retryBase and retryMax are seconds; `sleep` and `clock` exist for tests.
     */
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        string $baseUrl,
        private readonly array $options = [],
    ) {
        // Messages below never echo the key.
        if (preg_match('/^[\x21-\x7e]+$/', $apiKey) !== 1) {
            throw new SiteWarmException('apiKey must be non-empty printable ASCII without whitespace');
        }
        $this->apiKey = new Secret($apiKey);
        $this->baseUrl = self::normalize($baseUrl);
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->sleep = $options['sleep'] ?? static function (float $s): void {
            usleep((int) ($s * 1_000_000));
        };
        $this->clock = $options['clock'] ?? static fn (): float => microtime(true);
        $this->sites = new Sites($this);
        $this->warmRuns = new WarmRuns($this);
    }

    /** Never contains the key. */
    public function __toString(): string
    {
        return 'SiteWarm{baseUrl=' . $this->baseUrl . '}';
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('SiteWarm holds an API key and cannot be serialized');
    }

    private static function normalize(string $raw): string
    {
        $u = parse_url($raw);
        if ($u === false || !isset($u['scheme'], $u['host'])) {
            throw new SiteWarmException('baseUrl must be an absolute URL such as https://<host>/v1');
        }
        $host = strtolower($u['host']);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if ($u['scheme'] !== 'https' && !($u['scheme'] === 'http' && $loopback)) {
            throw new SiteWarmException('baseUrl must use https (http is only allowed for localhost)');
        }
        if (isset($u['user']) || isset($u['pass'])) {
            throw new SiteWarmException('baseUrl must not contain credentials');
        }
        if (isset($u['query']) || isset($u['fragment']) || str_contains($raw, '?') || str_contains($raw, '#')) {
            throw new SiteWarmException('baseUrl must not contain a query or fragment');
        }
        $port = isset($u['port']) ? ':' . $u['port'] : '';
        return $u['scheme'] . '://' . $u['host'] . $port . rtrim($u['path'] ?? '', '/');
    }

    /**
     * Calls one operation by operationId and returns the decoded JSON body (null for an empty body).
     *
     * @param array<string, string> $path
     * @param array<string, string|int|bool|null> $query null values are dropped
     * @return mixed
     */
    public function call(string $operationId, array $path = [], array $query = [], mixed $body = null, ?string $idempotencyKey = null): mixed
    {
        $r = $this->exchange($operationId, $path, $query, $body, $idempotencyKey);
        return $r['body'] === '' ? null : json_decode($r['body'], true);
    }

    /**
     * @param array<string, string> $path
     * @param array<string, string|int|bool|null> $query
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function exchange(string $operationId, array $path = [], array $query = [], mixed $body = null, ?string $idempotencyKey = null): array
    {
        $op = Operations::TABLE[$operationId] ?? null;
        if ($op === null) {
            throw new SiteWarmException("unknown operation \"{$operationId}\"");
        }
        [$method, $template, $idem] = $op;
        $url = $this->baseUrl . self::expand($template, $path);
        $query = self::checkedQuery($query);
        $pairs = [];
        foreach ($query as $k => $v) {
            if ($v !== null) {
                $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
            }
        }
        if ($pairs) {
            $url .= '?' . implode('&', $pairs);
        }
        $json = null;
        if ($body !== null) {
            $payload = $body instanceof Model\ModelInterface || is_object($body)
                ? ObjectSerializer::sanitizeForSerialization($body)
                : $body;
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new SiteWarmException('could not encode the request body');
            }
        }
        $sendsKey = $idem !== 'none' && $method === 'POST';
        // One key per call, reused by every retry, so a retried POST never starts a second run.
        $key = $sendsKey ? ($idempotencyKey ?? self::uuid()) : null;
        // 429 = not processed. 5xx, network errors and broken bodies may have been, so retry them only when
        // repeating is safe: GET, HEAD, PUT, OPTIONS, or a POST with an Idempotency-Key. DELETE and PATCH: 429 only.
        $retryAmbiguous = in_array($method, ['GET', 'HEAD', 'PUT', 'OPTIONS'], true) || $sendsKey;
        // A negative maxRetries or a timeout <= 0 keeps the default.
        $configuredRetries = $this->options['maxRetries'] ?? 3;
        $maxRetries = is_int($configuredRetries) && $configuredRetries >= 0 ? $configuredRetries : 3;
        $configuredTimeout = $this->options['timeout'] ?? 30.0;
        $timeout = (is_int($configuredTimeout) || is_float($configuredTimeout)) && $configuredTimeout > 0 ? (float) $configuredTimeout : 30.0;
        $retryBase = $this->options['retryBase'] ?? 0.5;
        $retryMax = $this->options['retryMax'] ?? 30.0;
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey->reveal(),
            'Accept' => 'application/json, application/problem+json',
            // An empty userAgent keeps the default.
            'User-Agent' => is_string($this->options['userAgent'] ?? null) && $this->options['userAgent'] !== ''
                ? $this->options['userAgent']
                : self::DEFAULT_USER_AGENT,
        ];
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }
        foreach ($headers as $value) {
            if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1) {
                // Repeating cannot fix it, and the value may be the key, so it is not echoed.
                throw new SiteWarmException('malformed request (invalid header value)');
            }
        }

        for ($attempt = 0;; $attempt++) {
            $last = $attempt >= $maxRetries;
            try {
                $resp = $this->transport->send($method, $url, $headers, $json, $timeout);
            } catch (ConnectionException $e) {
                // A body that breaks off carries the status: after a 429 nothing was processed, so retry for any method.
                // Certificate, unknown-host and malformed-request failures are permanent: repeating cannot fix them.
                if (!$last && ($retryAmbiguous || $e->status === 429) && !$e->permanent) {
                    ($this->sleep)(self::backoff($attempt, $retryBase, $retryMax));
                    continue;
                }
                // Rethrown fresh: its trace must not contain the transport frames, whose arguments include the headers.
                throw new ConnectionException($e->getMessage(), $e->timeout, $e->status, $e->permanent);
            } catch (ResponseTooLargeException $e) {
                throw new ResponseTooLargeException($e->limit);
            }
            if (strlen($resp['body']) > Limits::MAX_BODY) {
                throw new ResponseTooLargeException(Limits::MAX_BODY);
            }
            $status = $resp['status'];
            if ($status >= 200 && $status < 300) {
                self::assertJson($resp['body'], $status);
                return $resp;
            }
            $retryAfter = self::parseRetryAfter($resp['headers']['retry-after'] ?? null);
            $error = self::apiError($status, $resp, $retryAfter);
            $retryable = $status === 429 || ($status >= 500 && $status !== 501 && $retryAmbiguous);
            // A 409 "conflict" on a keyed POST is the original request still running (a timeout retry racing it).
            // "idempotency-key-reused" is a caller bug and is never retried.
            if ($status === 409 && $sendsKey && $error->errorCode === 'conflict') {
                $retryable = true;
            }
            if ($retryable && !$last) {
                // Never a hot retry on "Retry-After: 0" or a date in the past; jitter spreads clients out.
                // A server-provided wait longer than the cap is surfaced, not slept.
                $floored = $retryAfter === null ? null : max($retryAfter, (float) $retryBase);
                if ($floored === null || $floored <= $retryMax) {
                    ($this->sleep)($floored === null
                        ? self::backoff($attempt, $retryBase, $retryMax)
                        : $floored * (1 + (mt_rand() / mt_getrandmax()) / 4));
                    continue;
                }
            }
            throw $error;
        }
    }

    /** A 2xx body that is not JSON (an HTML page from a proxy, say) is a typed error that never includes the body. */
    private static function assertJson(string $body, int $status): void
    {
        if ($status === 204 || trim($body) === '') {
            return;
        }
        $valid = function_exists('json_validate') ? json_validate($body) : (json_decode($body) !== null || json_last_error() === JSON_ERROR_NONE);
        if (!$valid) {
            throw new DecodeException($status);
        }
    }

    /** @param array{status: int, headers: array<string, string>, body: string} $resp */
    private static function apiError(int $status, array $resp, ?float $retryAfter): ApiException
    {
        $p = json_decode($resp['body'], true);
        if (!is_array($p) || array_is_list($p)) {
            $p = ['type' => 'about:blank', 'title' => 'HTTP ' . $status, 'status' => $status];
        }
        $code = is_string($p['code'] ?? null) ? $p['code'] : null;
        if ($code === null && is_string($p['type'] ?? null)) {
            $t = rtrim($p['type'], '/');
            $slug = basename($t);
            if (preg_match('/^[a-z0-9-]+$/', $slug) === 1) {
                $code = $slug;
            }
        }
        $rl = trim($resp['headers']['ratelimit-remaining'] ?? '');
        return new ApiException($status, $p, $code, $retryAfter, ctype_digit($rl) ? (int) $rl : null);
    }

    public static function parseRetryAfter(?string $v): ?float
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (ctype_digit($s)) {
            return (float) $s;
        }
        $at = strtotime($s);
        return $at === false ? null : max(0.0, (float) ($at - time()));
    }

    /**
     * `limit` must be an integer in 1..100 (null, '' or 0 = server default, not sent); invalid values never reach the server.
     *
     * @param array<string, string|int|bool|null> $query
     * @return array<string, string|int|bool|null>
     */
    private static function checkedQuery(array $query): array
    {
        if (!array_key_exists('limit', $query)) {
            return $query;
        }
        $v = $query['limit'];
        if ($v === null || $v === '' || $v === 0 || $v === '0') {
            unset($query['limit']);
            return $query;
        }
        $n = is_int($v) ? $v : (is_string($v) && preg_match('/^\d+$/', $v) === 1 ? (int) $v : null);
        if ($n === null || $n < 1 || $n > 100) {
            throw new SiteWarmException('limit must be an integer between 1 and 100');
        }
        return $query;
    }

    private static function backoff(int $attempt, float $base, float $max): float
    {
        $ceil = min($max, $base * (2 ** $attempt));
        return $ceil / 2 + (mt_rand() / mt_getrandmax()) * $ceil / 2;
    }

    /** @param array<string, string> $values */
    private static function expand(string $template, array $values): string
    {
        return (string) preg_replace_callback('/\{(\w+)}/', static function (array $m) use ($values): string {
            $v = $values[$m[1]] ?? '';
            if ($v === '') {
                throw new SiteWarmException("missing path parameter \"{$m[1]}\"");
            }
            return rawurlencode($v);
        }, $template);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Iterates every item of a list operation across pages. Cursors are opaque.
     *
     * @param array<string, string> $path
     * @param array<string, string|int|bool|null> $query
     * @param class-string|null $type model class to decode items into; null yields associative arrays
     * @param int $maxPages throws instead of ending silently when this many pages did not reach the end
     * @return \Generator<int, mixed>
     */
    public function items(string $operationId, array $path = [], array $query = [], ?string $type = null, int $maxPages = Limits::MAX_PAGES): \Generator
    {
        $cursor = isset($query['cursor']) ? (string) $query['cursor'] : null;
        $seen = $cursor !== null ? [$cursor => true] : [];
        for ($pages = 0; $pages < $maxPages; $pages++) {
            $q = $cursor === null ? $query : [...$query, 'cursor' => $cursor];
            $r = $this->exchange($operationId, $path, $q);
            $doc = json_decode($r['body']);
            foreach ($doc->data ?? [] as $item) {
                yield $type === null ? json_decode(json_encode($item), true) : ObjectSerializer::deserialize($item, $type);
            }
            $info = $doc->page ?? null;
            $next = is_string($info->next_cursor ?? null) ? $info->next_cursor : null;
            if (!($info->has_more ?? false) || $next === null || $next === '') {
                return;
            }
            if (isset($seen[$next])) {
                throw new SiteWarmException('pagination cursor did not advance');
            }
            $seen[$next] = true;
            $cursor = $next;
        }
        throw new SiteWarmException("pagination stopped after {$maxPages} pages without reaching the end");
    }

    /**
     * @param array<string, string> $path
     * @param array<string, string|int|bool|null> $query
     * @param class-string $type
     */
    public function typed(string $operationId, string $type, array $path = [], array $query = [], mixed $body = null, ?string $idempotencyKey = null): mixed
    {
        $r = $this->exchange($operationId, $path, $query, $body, $idempotencyKey);
        return ObjectSerializer::deserialize(json_decode($r['body']), $type);
    }

    /** @internal */
    public function now(): float
    {
        return ($this->clock)();
    }

    /** @internal */
    public function pause(float $seconds): void
    {
        ($this->sleep)($seconds);
    }
}

final class Sites
{
    public function __construct(private readonly SiteWarm $c)
    {
    }

    public function get(string $siteId): Site
    {
        return $this->c->typed('getSite', Site::class, ['siteId' => $siteId]);
    }

    public function create(SiteCreate $body): Site
    {
        return $this->c->typed('createSite', Site::class, body: $body);
    }

    /** @return \Generator<int, Site> */
    public function all(): \Generator
    {
        return $this->c->items('listSites', type: Site::class);
    }
}

final class WarmRuns
{
    public function __construct(private readonly SiteWarm $c)
    {
    }

    /** Starts a run (202). An Idempotency-Key is always sent (random unless `$idempotencyKey` is given). */
    public function create(string $siteId, WarmRunCreate $body, ?string $idempotencyKey = null): WarmRun
    {
        return $this->c->typed('createWarmRun', WarmRun::class, ['siteId' => $siteId], body: $body, idempotencyKey: $idempotencyKey);
    }

    public function get(string $runId): WarmRun
    {
        return $this->c->typed('getWarmRun', WarmRun::class, ['runId' => $runId]);
    }

    public function cancel(string $runId): WarmRun
    {
        return $this->c->typed('cancelWarmRun', WarmRun::class, ['runId' => $runId]);
    }

    /**
     * All results of a run; `$finalState` (null for all) filters by final cache state.
     *
     * @return \Generator<int, Result>
     */
    public function results(string $runId, ?string $finalState = null): \Generator
    {
        return $this->c->items('listWarmRunResults', ['runId' => $runId], ['final_state' => $finalState], Result::class);
    }

    /**
     * Polls until the run is completed, canceled or failed, honoring Retry-After (bounded to 1 s .. 60 s).
     * Throws {@see WaitTimeoutException} on timeout and does NOT cancel the run.
     */
    public function waitFor(string $runId, float $timeout = 600.0, float $pollInterval = 2.0): WarmRun
    {
        $deadline = $this->c->now() + $timeout;
        while (true) {
            $r = $this->c->exchange('getWarmRun', ['runId' => $runId]);
            $doc = json_decode($r['body']);
            if (in_array($doc->status ?? null, ['completed', 'canceled', 'failed'], true)) {
                return \Sitewarm\Sdk\ObjectSerializer::deserialize($doc, WarmRun::class);
            }
            $hint = SiteWarm::parseRetryAfter($r['headers']['retry-after'] ?? null);
            $delay = min(60.0, max(1.0, $hint ?? $pollInterval));
            if ($this->c->now() + $delay > $deadline) {
                throw new WaitTimeoutException('timed out waiting for the run; it was not canceled');
            }
            $this->c->pause($delay);
        }
    }
}
