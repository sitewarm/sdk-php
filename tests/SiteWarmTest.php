<?php

declare(strict_types=1);

namespace Sitewarm\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Sitewarm\Sdk\ApiException;
use Sitewarm\Sdk\ConnectionException;
use Sitewarm\Sdk\Model\WarmRunCreate;
use Sitewarm\Sdk\Operations;
use Sitewarm\Sdk\SiteWarm;
use Sitewarm\Sdk\SiteWarmException;
use Sitewarm\Sdk\Transport;
use Sitewarm\Sdk\WaitTimeoutException;

final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $seen = [];

    /** @param callable(int, string, string): (array{status: int, headers?: array<string, string>, body?: string}|\Throwable) $handler */
    public function __construct(private $handler)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $this->seen[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $r = ($this->handler)(count($this->seen), $method, $url);
        if ($r instanceof \Throwable) {
            throw $r;
        }
        return ['status' => $r['status'], 'headers' => $r['headers'] ?? [], 'body' => $r['body'] ?? ''];
    }
}

final class SiteWarmTest extends TestCase
{
    private const KEY = 'swk_secret_key';

    /** @var list<float> */
    private array $slept = [];
    private float $now = 1000.0;

    /** @param array<string, mixed> $options */
    private function client(callable $h, array $options = []): array
    {
        $t = new FakeTransport($h);
        $c = new SiteWarm(self::KEY, 'https://api.example.test/v1', $options + [
            'transport' => $t,
            'sleep' => function (float $s): void {
                $this->slept[] = $s;
                $this->now += $s;
            },
            'clock' => fn (): float => $this->now,
        ]);
        return [$c, $t];
    }

    public function testRequestShapeAndAuth(): void
    {
        [$c, $t] = $this->client(fn () => ['status' => 200, 'body' => '{"id":"s1"}']);
        $r = $c->call('getSite', ['siteId' => 'a/b']);
        $this->assertSame('s1', $r['id']);
        $this->assertSame('https://api.example.test/v1/sites/a%2Fb', $t->seen[0]['url']);
        $this->assertSame('Bearer ' . self::KEY, $t->seen[0]['headers']['Authorization']);
        $this->assertArrayNotHasKey('Idempotency-Key', $t->seen[0]['headers']);
    }

    public function testProblemBecomesTypedErrorWithoutKey(): void
    {
        $cases = [
            [401, ApiException::AUTHENTICATION], [403, ApiException::PERMISSION], [404, ApiException::NOT_FOUND],
            [409, ApiException::CONFLICT], [422, ApiException::VALIDATION], [429, ApiException::RATE_LIMITED],
            [500, ApiException::SERVER],
        ];
        foreach ($cases as [$status, $kind]) {
            [$c] = $this->client(fn () => [
                'status' => $status,
                'headers' => ['retry-after' => '0', 'ratelimit-remaining' => '0'],
                'body' => '{"type":"https://x.test/problems/some-slug","title":"T","detail":"D"}',
            ], ['maxRetries' => 0]);
            try {
                $c->call('getSite', ['siteId' => 's1']);
                $this->fail('expected ApiException');
            } catch (ApiException $e) {
                $this->assertSame($kind, $e->kind());
                $this->assertSame($status, $e->status);
                $this->assertSame('some-slug', $e->errorCode);
                $this->assertSame(0, $e->rateLimitRemaining);
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        }
    }

    public function testNonProblemBodyIsSynthesized(): void
    {
        [$c] = $this->client(fn () => ['status' => 502, 'body' => '<html>bad gateway</html>'], ['maxRetries' => 0]);
        try {
            $c->call('getSite', ['siteId' => 's1']);
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('HTTP 502', $e->problem['title']);
            $this->assertNull($e->errorCode);
        }
    }

    public function testRetries429ThenSucceeds(): void
    {
        [$c, $t] = $this->client(fn (int $n) => $n < 3
            ? ['status' => 429, 'headers' => ['retry-after' => '2'], 'body' => '{}']
            : ['status' => 200, 'body' => '{"id":"s1"}']);
        $c->call('getSite', ['siteId' => 's1']);
        $this->assertCount(3, $t->seen);
        $this->assertCount(2, $this->slept);
        foreach ($this->slept as $w) {
            $this->assertGreaterThanOrEqual(2.0, $w); // Retry-After plus up to 25% jitter
            $this->assertLessThanOrEqual(2.5, $w);
        }
    }

    public function testRetryAfterBeyondCapIsSurfaced(): void
    {
        [$c, $t] = $this->client(fn () => ['status' => 429, 'headers' => ['retry-after' => '120'], 'body' => '{}']);
        try {
            $c->call('getSite', ['siteId' => 's1']);
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertCount(1, $t->seen);
            $this->assertSame(120.0, $e->retryAfter);
            $this->assertSame([], $this->slept);
        }
    }

    public function testKeyedPostIsRetriedWithOneKey(): void
    {
        [$c, $t] = $this->client(fn () => ['status' => 503, 'body' => '{}'], ['maxRetries' => 2]);
        try {
            $c->warmRuns->create('s1', new WarmRunCreate());
            $this->fail('expected ApiException');
        } catch (ApiException) {
        }
        $this->assertCount(3, $t->seen);
        $keys = array_unique(array_map(fn ($s) => $s['headers']['Idempotency-Key'] ?? '', $t->seen));
        $this->assertCount(1, $keys);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $keys[0]);
    }

    public function testGivenIdempotencyKeyIsSent(): void
    {
        [$c, $t] = $this->client(fn () => ['status' => 202, 'body' => '{"id":"run1"}']);
        $c->call('createWarmRun', ['siteId' => 's1'], [], new WarmRunCreate(), 'mine');
        $this->assertSame('mine', $t->seen[0]['headers']['Idempotency-Key']);
    }

    public function testConnectionErrorHasNoKeyOrCause(): void
    {
        [$c, $t] = $this->client(fn () => new ConnectionException('request failed'), ['maxRetries' => 1]);
        try {
            $c->call('getSite', ['siteId' => 's1']);
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertCount(2, $t->seen);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function testPaginationFollowsCursorAndDetectsStall(): void
    {
        [$c] = $this->client(fn (int $n) => $n === 1
            ? ['status' => 200, 'body' => '{"data":[{"id":"a"},{"id":"b"}],"page":{"has_more":true,"next_cursor":"c1"}}']
            : ['status' => 200, 'body' => '{"data":[{"id":"c"}],"page":{"has_more":false,"next_cursor":null}}']);
        $ids = [];
        foreach ($c->sites->all() as $s) {
            $ids[] = $s->getId();
        }
        $this->assertSame(['a', 'b', 'c'], $ids);

        [$stuck] = $this->client(fn () => ['status' => 200, 'body' => '{"data":[{"id":"a"}],"page":{"has_more":true,"next_cursor":"c1"}}']);
        $this->expectException(SiteWarmException::class);
        $this->expectExceptionMessage('did not advance');
        foreach ($stuck->sites->all() as $_) {
        }
    }

    public function testWaitPollsUntilTerminalAndTimesOutWithoutCancel(): void
    {
        $statuses = ['queued', 'running', 'completed'];
        [$c] = $this->client(fn (int $n) => [
            'status' => 200,
            'headers' => ['retry-after' => '0'],
            'body' => '{"id":"r1","site_id":"s","status":"' . $statuses[min($n, 3) - 1] . '"}',
        ]);
        $run = $c->warmRuns->waitFor('r1', 60.0, 1.0);
        $this->assertSame('completed', (string) $run->getStatus());

        [$never, $t] = $this->client(fn () => ['status' => 200, 'body' => '{"id":"r1","status":"running"}']);
        try {
            $never->warmRuns->waitFor('r1', 5.0, 2.0);
            $this->fail('expected WaitTimeoutException');
        } catch (WaitTimeoutException) {
            $this->assertSame(['GET'], array_values(array_unique(array_column($t->seen, 'method'))));
        }
    }

    public function testValidation(): void
    {
        foreach (['http://api.example.test/v1', 'https://u:p@api.example.test', 'https://x.test/v1?a=1', 'api.example.test', ''] as $u) {
            try {
                new SiteWarm(self::KEY, $u);
                $this->fail("accepted {$u}");
            } catch (SiteWarmException) {
                $this->addToAssertionCount(1);
            }
        }
        new SiteWarm(self::KEY, 'http://localhost:8080/v1');
        foreach (['', 'has space', "tab\t"] as $k) {
            try {
                new SiteWarm($k, 'https://x.test/v1');
                $this->fail('accepted a bad key');
            } catch (SiteWarmException $e) {
                $this->assertFalse($k !== '' && str_contains($e->getMessage(), $k));
            }
        }
        $c = new SiteWarm(self::KEY, 'https://x.test/v1');
        $this->assertStringNotContainsString(self::KEY, (string) $c);
        $this->assertStringNotContainsString(self::KEY, print_r($c, true));
        $this->assertStringNotContainsString(self::KEY, var_export($c->__debugInfo(), true));
        $this->expectException(\LogicException::class);
        serialize($c);
    }

    public function testUnknownOperationAndMissingPathParam(): void
    {
        [$c] = $this->client(fn () => ['status' => 200, 'body' => '{}']);
        try {
            $c->call('nope');
            $this->fail('expected exception');
        } catch (SiteWarmException $e) {
            $this->assertStringContainsString('unknown operation', $e->getMessage());
        }
        $this->expectExceptionMessage('missing path parameter');
        $c->call('getSite');
    }

    public function testOperationTableMatchesSpec(): void
    {
        $this->assertCount(36, Operations::TABLE);
        $this->assertSame('required', Operations::TABLE['createWarmRun'][2]);
        $this->assertSame('none', Operations::TABLE['getWarmRun'][2]);
        $spec = (string) file_get_contents(__DIR__ . '/../openapi/openapi.yaml');
        $this->assertStringNotContainsString('x-open-question', $spec);
        foreach (array_keys(Operations::TABLE) as $id) {
            $this->assertStringContainsString("operationId: {$id}", $spec);
        }
    }

    public function testRetryAfterParsing(): void
    {
        $this->assertSame(7.0, SiteWarm::parseRetryAfter('7'));
        $this->assertNull(SiteWarm::parseRetryAfter('garbage'));
        $this->assertNull(SiteWarm::parseRetryAfter(null));
    }
}
