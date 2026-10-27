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
    public readonly Sites $sites;
    public readonly WarmRuns $warmRuns;

    private readonly string $baseUrl;
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
        #[\SensitiveParameter] private readonly string $apiKey,
        string $baseUrl,
        private readonly array $options = [],
    ) {
        // Messages below never echo the key.
        if (preg_match('/^[\x21-\x7e]+$/', $apiKey) !== 1) {
            throw new SiteWarmException('apiKey must be non-empty printable ASCII without whitespace');
        }
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
        // 429 = not processed. 5xx and network errors may have been, so retry them only when repeating is safe.
        $retryAmbiguous = $method !== 'POST' || $sendsKey;
        $maxRetries = $this->options['maxRetries'] ?? 3;
        $retryBase = $this->options['retryBase'] ?? 0.5;
        $retryMax = $this->options['retryMax'] ?? 30.0;
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json, application/problem+json',
            'User-Agent' => $this->options['userAgent'] ?? 'sitewarm-php',
        ];
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        for ($attempt = 0;; $attempt++) {
            $last = $attempt >= $maxRetries;
            try {
                $resp = $this->transport->send($method, $url, $headers, $json, $this->options['timeout'] ?? 30.0);
            } catch (ConnectionException $e) {
                if (!$last && $retryAmbiguous) {
                    ($this->sleep)(self::backoff($attempt, $retryBase, $retryMax));
                    continue;
                }
                throw $e;
            }
            $status = $resp['status'];
            if ($status >= 200 && $status < 300) {
                return $resp;
            }
            $retryAfter = self::parseRetryAfter($resp['headers']['retry-after'] ?? null);
            $retryable = $status === 429 || ($status >= 500 && $status !== 501 && $retryAmbiguous);
            if ($retryable && !$last) {
                $wait = $retryAfter ?? self::backoff($attempt, $retryBase, $retryMax);
                if ($wait <= $retryMax) { // a longer server-provided wait is surfaced, not ignored
                    ($this->sleep)($wait);
                    continue;
                }
            }
            throw self::apiError($status, $resp, $retryAfter);
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
     * @return \Generator<int, mixed>
     */
    public function items(string $operationId, array $path = [], array $query = [], ?string $type = null): \Generator
    {
        $cursor = isset($query['cursor']) ? (string) $query['cursor'] : null;
        for ($pages = 0; $pages < 10_000; $pages++) {
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
            if ($next === $cursor) {
                throw new SiteWarmException('pagination cursor did not advance');
            }
            $cursor = $next;
        }
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
