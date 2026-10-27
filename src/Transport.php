<?php

declare(strict_types=1);

namespace Sitewarm\Sdk;

/** Sends one HTTP request. Replaceable in tests. Throws {@see ConnectionException} when there is no response. */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string} header names lower-cased
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array;
}
