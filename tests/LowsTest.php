<?php

declare(strict_types=1);

namespace Sitewarm\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Sitewarm\Sdk\ApiException;
use Sitewarm\Sdk\ConnectionException;
use Sitewarm\Sdk\CurlTransport;
use Sitewarm\Sdk\DecodeException;
use Sitewarm\Sdk\SiteWarm;
use Sitewarm\Sdk\SiteWarmException;
use Sitewarm\Sdk\Transport;

final class LowsTransport implements Transport
{
    public int $calls = 0;
    /** @var list<array<string, string>> */
    public array $headers = [];

    /** @param callable(int): (array{status: int, headers?: array<string, string>, body?: string}|\Throwable) $handler */
    public function __construct(private $handler)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $this->calls++;
        $this->headers[] = $headers;
        $r = ($this->handler)($this->calls);
        if ($r instanceof \Throwable) {
            throw $r;
        }
        return ['status' => $r['status'], 'headers' => $r['headers'] ?? [], 'body' => $r['body'] ?? ''];
    }
}

final class LowsTest extends TestCase
{
    private const KEY = 'swk_secret_key';

    /** @var list<float> */
    private array $sleeps = [];

    /** @param array<string, mixed> $options @return array{0: SiteWarm, 1: LowsTransport} */
    private function client(callable $h, array $options = [], string $base = 'https://api.example.test/v1'): array
    {
        $t = new LowsTransport($h);
        $c = new SiteWarm(self::KEY, $base, $options + ['transport' => $t, 'sleep' => function (float $s): void {
            $this->sleeps[] = $s;
        }]);
        return [$c, $t];
    }

    private static function problem(int $status, string $slug): array
    {
        return ['status' => $status, 'body' => json_encode(['type' => "https://x.test/problems/{$slug}", 'title' => 'c', 'status' => $status])];
    }

    public function testConflictOnKeyedPostIsRetriedButKeyReusedIsNot(): void
    {
        foreach (['conflict' => 3, 'idempotency-key-reused' => 1] as $slug => $calls) {
            [$c, $t] = $this->client(fn () => self::problem(409, $slug), ['maxRetries' => 2]);
            try {
                $c->call('createSite', body: ['hostname' => 'example.com']);
                $this->fail('expected ApiException');
            } catch (ApiException $e) {
                $this->assertSame($slug, $e->errorCode);
            }
            $this->assertSame($calls, $t->calls, $slug);
        }
    }

    public function testConflictOnUnkeyedOperationIsNotRetried(): void
    {
        [$c, $t] = $this->client(fn () => self::problem(409, 'conflict'), ['maxRetries' => 2]);
        try {
            $c->call('deleteSite', ['siteId' => 's1']);
            $this->fail('expected ApiException');
        } catch (ApiException) {
        }
        $this->assertSame(1, $t->calls);
    }

    public function testPermanentConnectionErrorsAreNotRetried(): void
    {
        foreach ([[true, 1], [false, 3]] as [$permanent, $calls]) {
            [$c, $t] = $this->client(fn () => new ConnectionException('request failed', false, null, $permanent), ['maxRetries' => 2]);
            try {
                $c->call('getSite', ['siteId' => 's1']);
                $this->fail('expected ConnectionException');
            } catch (ConnectionException $e) {
                $this->assertSame($permanent, $e->permanent);
            }
            $this->assertSame($calls, $t->calls);
        }
    }

    public function testCurlClassifiesUnknownHostAsPermanentAndRefusedAsTransient(): void
    {
        $curl = new CurlTransport();
        try {
            $curl->send('GET', 'https://nonexistent.invalid/v1/x', [], null, 5.0);
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertTrue($e->permanent);
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $port = (int) substr((string) stream_socket_get_name($probe, false), strlen('127.0.0.1:'));
        fclose($probe);
        try {
            $curl->send('GET', "http://127.0.0.1:{$port}/v1/x", [], null, 5.0);
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertFalse($e->permanent);
        }
    }

    public function testInvalidHeaderValueIsRejectedWithoutSendingOrEchoing(): void
    {
        [$c, $t] = $this->client(fn () => ['status' => 200, 'body' => '{}'], ['userAgent' => "evil\r\nX-Injected: 1"]);
        try {
            $c->call('getSite', ['siteId' => 's1']);
            $this->fail('expected SiteWarmException');
        } catch (SiteWarmException $e) {
            $this->assertStringNotContainsString('Injected', $e->getMessage());
        }
        $this->assertSame(0, $t->calls);
    }

    public function testRetryAfterZeroWaitsTheBaseWithJitter(): void
    {
        [$c] = $this->client(fn (int $n) => $n === 1
            ? ['status' => 429, 'headers' => ['retry-after' => '0'], 'body' => '{}']
            : ['status' => 200, 'body' => '{"id":"s1"}'], ['retryBase' => 0.2, 'retryMax' => 5.0]);
        $c->call('getSite', ['siteId' => 's1']);
        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThanOrEqual(0.2, $this->sleeps[0]);
        $this->assertLessThanOrEqual(0.25, $this->sleeps[0]);
    }

    public function testPastHttpDateIsFlooredToo(): void
    {
        [$c] = $this->client(fn (int $n) => $n === 1
            ? ['status' => 429, 'headers' => ['retry-after' => 'Mon, 01 Jan 2001 00:00:00 GMT'], 'body' => '{}']
            : ['status' => 200, 'body' => '{"id":"s1"}'], ['retryBase' => 0.2, 'retryMax' => 5.0]);
        $c->call('getSite', ['siteId' => 's1']);
        $this->assertGreaterThanOrEqual(0.2, $this->sleeps[0]);
    }

    public function testRetryAfterAboveTheCapIsSurfacedNotSlept(): void
    {
        [$c, $t] = $this->client(fn () => ['status' => 429, 'headers' => ['retry-after' => '60'], 'body' => '{}'], ['retryMax' => 5.0]);
        try {
            $c->call('getSite', ['siteId' => 's1']);
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(60.0, $e->retryAfter);
        }
        $this->assertSame(1, $t->calls);
        $this->assertSame([], $this->sleeps);
    }

    public function testNonJsonSuccessIsATypedDecodeError(): void
    {
        [$c] = $this->client(fn () => ['status' => 200, 'body' => '<html>secret-proxy-page</html>']);
        try {
            $c->call('getSite', ['siteId' => 's1']);
            $this->fail('expected DecodeException');
        } catch (DecodeException $e) {
            $this->assertSame(200, $e->status);
            $this->assertInstanceOf(SiteWarmException::class, $e);
            $this->assertStringNotContainsString('secret-proxy-page', $e->getMessage());
        }
    }

    public function testUserAgentCarriesVersionAndUrlAndEmptyKeepsTheDefault(): void
    {
        $default = 'sitewarm-php/' . SiteWarm::VERSION . ' (+https://github.com/sitewarm/sdk-php)';
        $seen = [];
        foreach ([[], ['userAgent' => ''], ['userAgent' => 'mine/1']] as $opts) {
            [$c, $t] = $this->client(fn () => ['status' => 200, 'body' => '{}'], $opts);
            $c->call('getSite', ['siteId' => 's1']);
            $seen[] = $t->headers[0]['User-Agent'];
        }
        $this->assertSame([$default, $default, 'mine/1'], $seen);
    }

    public function testBasePathKeepsPercentEncoding(): void
    {
        $url = '';
        $t = new class implements Transport {
            public string $url = '';

            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
            {
                $this->url = $url;
                return ['status' => 200, 'headers' => [], 'body' => '{}'];
            }
        };
        (new SiteWarm(self::KEY, 'https://x.test/a%2Fb/v1', ['transport' => $t]))->call('getSite', ['siteId' => 's1']);
        $this->assertSame('https://x.test/a%2Fb/v1/sites/s1', $t->url);
    }
}
