<?php

declare(strict_types=1);

namespace Sitewarm\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Sitewarm\Sdk\ApiException;
use Sitewarm\Sdk\ConnectionException;
use Sitewarm\Sdk\CurlTransport;
use Sitewarm\Sdk\ResponseTooLargeException;
use Sitewarm\Sdk\SiteWarm;
use Sitewarm\Sdk\SiteWarmException;
use Sitewarm\Sdk\Transport;

final class ScriptedTransport implements Transport
{
    public int $calls = 0;

    /** @param callable(int): (array{status: int, headers?: array<string, string>, body?: string}|\Throwable) $handler */
    public function __construct(private $handler)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $this->calls++;
        $this->lastUrl = $url;
        $this->lastTimeout = $timeout;
        $r = ($this->handler)($this->calls);
        if ($r instanceof \Throwable) {
            throw $r;
        }
        return ['status' => $r['status'], 'headers' => $r['headers'] ?? [], 'body' => $r['body'] ?? ''];
    }

    public string $lastUrl = '';
    public float $lastTimeout = 0.0;
}

final class HardeningTest extends TestCase
{
    private const KEY = 'swk_secret_key';

    /** @var resource|null */
    private $server = null;
    private string $log = '';
    private int $port = 0;

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        if ($this->log !== '' && is_file($this->log)) {
            unlink($this->log);
        }
    }

    /** @param array<string, mixed> $options */
    private function client(callable $h, array $options = []): array
    {
        $t = new ScriptedTransport($h);
        $c = new SiteWarm(self::KEY, 'https://api.example.test/v1', $options + ['transport' => $t, 'sleep' => static function (float $s): void {
        }]);
        return [$c, $t];
    }

    private function startServer(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $this->port = (int) substr((string) stream_socket_get_name($probe, false), strlen('127.0.0.1:'));
        fclose($probe);
        $this->log = sys_get_temp_dir() . '/sitewarm-hits-' . bin2hex(random_bytes(4));
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/fixtures/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['HIT_LOG' => $this->log, 'PATH' => (string) getenv('PATH')],
        );
        for ($i = 0; $i < 100; $i++) {
            $s = @fsockopen('127.0.0.1', $this->port);
            if ($s !== false) {
                fclose($s);
                return;
            }
            usleep(50_000);
        }
        $this->fail('built-in server did not start');
    }

    private function http(): SiteWarm
    {
        return new SiteWarm(self::KEY, 'http://127.0.0.1:' . $this->port . '/v1', ['maxRetries' => 0]);
    }

    public function testNonPositiveTimeoutAndNegativeRetriesKeepDefaults(): void
    {
        foreach ([0, -1, 0.0, -0.5] as $bad) {
            [$c, $t] = $this->client(fn () => ['status' => 200, 'body' => '{}'], ['timeout' => $bad]);
            $c->call('getSite', ['siteId' => 's1']);
            $this->assertSame(30.0, $t->lastTimeout);
        }
        [$c, $t] = $this->client(fn () => ['status' => 200, 'body' => '{}'], ['timeout' => 2]);
        $c->call('getSite', ['siteId' => 's1']);
        $this->assertSame(2.0, $t->lastTimeout);

        [$c, $t] = $this->client(fn () => ['status' => 503, 'body' => '{}'], ['maxRetries' => -1]);
        try {
            $c->call('getSite', ['siteId' => 's1']);
            $this->fail('expected ApiException');
        } catch (ApiException) {
        }
        $this->assertSame(4, $t->calls);
    }

    public function testBrokenBodyIsRetriedOnRepeatableCalls(): void
    {
        [$c, $t] = $this->client(fn (int $n) => $n === 1
            ? new ConnectionException('request failed', false, 200)
            : ['status' => 200, 'body' => '{"id":"s1"}']);
        $this->assertSame('s1', $c->call('getSite', ['siteId' => 's1'])['id']);
        $this->assertSame(2, $t->calls);
    }

    public function testBrokenBodyOnDeleteIsNotRetriedButAfter429Is(): void
    {
        [$c, $t] = $this->client(fn () => new ConnectionException('request failed', false, 200), ['maxRetries' => 2]);
        try {
            $c->call('deleteSite', ['siteId' => 's1']);
            $this->fail('expected ConnectionException');
        } catch (ConnectionException) {
        }
        $this->assertSame(1, $t->calls);

        [$c, $t] = $this->client(fn (int $n) => $n === 1
            ? new ConnectionException('request failed', false, 429)
            : ['status' => 200, 'body' => '{}']);
        $c->call('deleteSite', ['siteId' => 's1']);
        $this->assertSame(2, $t->calls);
    }

    public function testOversizedBodyFromAnyTransportIsTypedAndNotRetried(): void
    {
        [$c, $t] = $this->client(fn () => ['status' => 200, 'body' => str_repeat('x', 32 * 1024 * 1024 + 1)]);
        $this->expectException(ResponseTooLargeException::class);
        try {
            $c->call('getSite', ['siteId' => 's1']);
        } finally {
            $this->assertSame(1, $t->calls);
        }
    }

    public function testOnlyRepeatableMethodsRetryServerErrors(): void
    {
        $cases = [
            ['getSite', ['siteId' => 's1'], 3],
            ['replaceUrlSet', ['siteId' => 's1', 'urlSetId' => 'u1'], 3],
            ['deleteSite', ['siteId' => 's1'], 1],
            ['updateSite', ['siteId' => 's1'], 1],
        ];
        foreach ($cases as [$op, $path, $want]) {
            [$c, $t] = $this->client(fn () => ['status' => 503, 'body' => '{}'], ['maxRetries' => 2]);
            try {
                $c->call($op, $path);
                $this->fail('expected ApiException');
            } catch (ApiException) {
            }
            $this->assertSame($want, $t->calls, $op);
        }
        [$c, $t] = $this->client(fn (int $n) => $n < 3
            ? ['status' => 429, 'headers' => ['retry-after' => '0'], 'body' => '{}']
            : ['status' => 200, 'body' => '{}']);
        $c->call('deleteSite', ['siteId' => 's1']);
        $this->assertSame(3, $t->calls);
    }

    public function testPaginationErrorsOnRepeatedCursorAndAtPageCap(): void
    {
        [$c] = $this->client(function (int $n): array {
            return ['status' => 200, 'body' => json_encode(['data' => [['id' => 'x']], 'page' => ['has_more' => true, 'next_cursor' => $n % 2 ? 'b' : 'a']])];
        });
        try {
            iterator_to_array($c->items('listSites'), false);
            $this->fail('expected SiteWarmException');
        } catch (SiteWarmException $e) {
            $this->assertStringContainsString('did not advance', $e->getMessage());
        }

        [$c, $t] = $this->client(fn (int $n) => ['status' => 200, 'body' => json_encode(['data' => [], 'page' => ['has_more' => true, 'next_cursor' => "c{$n}"]])]);
        try {
            iterator_to_array($c->items('listSites', maxPages: 5), false);
            $this->fail('expected SiteWarmException');
        } catch (SiteWarmException $e) {
            $this->assertStringContainsString('stopped after 5 pages', $e->getMessage());
        }
        $this->assertSame(5, $t->calls);
    }

    public function testListLimitIsValidatedBeforeSending(): void
    {
        [$c, $t] = $this->client(fn () => ['status' => 200, 'body' => '{"data":[],"page":{"has_more":false}}']);
        foreach ([-1, 101, 'x', '1.5', 1.5, true] as $bad) {
            try {
                $c->call('listSites', query: ['limit' => $bad]);
                $this->fail('accepted limit ' . var_export($bad, true));
            } catch (SiteWarmException $e) {
                $this->assertStringContainsString('limit', $e->getMessage());
            }
        }
        $this->assertSame(0, $t->calls);
        $urls = [];
        foreach ([1, 100, '5', 0, null] as $ok) {
            $c->call('listSites', query: ['limit' => $ok]);
            $urls[] = $t->lastUrl;
        }
        $this->assertSame([
            'https://api.example.test/v1/sites?limit=1',
            'https://api.example.test/v1/sites?limit=100',
            'https://api.example.test/v1/sites?limit=5',
            'https://api.example.test/v1/sites',
            'https://api.example.test/v1/sites',
        ], $urls);
    }

    public function testKeyIsInNoDefaultDump(): void
    {
        $c = new SiteWarm(self::KEY, 'https://api.example.test/v1');
        ob_start();
        var_dump($c);
        print_r($c);
        @var_export($c); // warns about the client <-> resource cycle; only the output matters
        var_dump((array) $c);
        print_r((array) $c);
        @var_export((array) $c);
        echo json_encode($c), (string) $c, print_r($c, true), @var_export($c, true);
        $out = (string) ob_get_clean();
        $this->assertStringNotContainsString(self::KEY, $out);
        $this->assertStringNotContainsString('swk_', $out);
        $this->assertStringNotContainsString(self::KEY, (string) json_encode(get_object_vars($c)));

        try {
            serialize($c);
            $this->fail('serialized');
        } catch (\LogicException) {
        }
    }

    public function testSecretCannotBeSerializedOrCloned(): void
    {
        $s = new \Sitewarm\Sdk\Secret(self::KEY);
        $this->assertSame(self::KEY, $s->reveal());
        $this->assertStringNotContainsString(self::KEY, print_r($s, true) . var_export($s, true) . (string) $s);
        try {
            serialize($s);
            $this->fail('serialized');
        } catch (\LogicException) {
        }
    }

    public function testConnectionErrorTraceDoesNotContainTheKey(): void
    {
        $c = new SiteWarm(self::KEY, 'http://127.0.0.1:1/v1', ['maxRetries' => 0]);
        try {
            $c->call('getSite', ['siteId' => 's1']);
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringNotContainsString(self::KEY, (string) $e);
            $this->assertStringNotContainsString(self::KEY, $e->getTraceAsString());
        }
    }

    public function testCurlTransportDoesNotFollowRedirects(): void
    {
        $this->startServer();
        try {
            $this->http()->call('getSite', ['siteId' => 'redir']);
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(302, $e->status);
        }
        $hits = (string) file_get_contents($this->log);
        $this->assertStringContainsString('/v1/sites/redir', $hits);
        $this->assertStringNotContainsString('/other', $hits);
    }

    public function testCurlTransportReportsBrokenAndOversizedBodies(): void
    {
        $this->startServer();
        $t = new CurlTransport();
        $base = 'http://127.0.0.1:' . $this->port . '/v1/sites/';
        $ok = $t->send('GET', $base . 'ok', [], null, 10.0);
        $this->assertSame(200, $ok['status']);
        $this->assertSame('{"id":"s1"}', $ok['body']);

        try {
            $t->send('GET', $base . 'broken', [], null, 10.0);
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame(200, $e->status);
        }

        $this->expectException(ResponseTooLargeException::class);
        $t->send('GET', $base . 'big', [], null, 30.0);
    }
}
