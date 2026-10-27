<?php

declare(strict_types=1);

namespace Sitewarm\Sdk;

/** The default transport on ext-curl. Redirects are never followed, so the key never goes to another origin. */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ConnectionException('request failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($out === false || $errno !== 0) {
            // The curl message can contain the URL, so it is not passed on.
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;
            throw new ConnectionException($timedOut ? 'request timed out' : 'request failed', $timedOut);
        }
        return ['status' => $status, 'headers' => $received, 'body' => (string) $out];
    }
}
