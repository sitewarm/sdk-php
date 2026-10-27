<?php

declare(strict_types=1);

namespace Sitewarm\Sdk;

/**
 * The request did not produce a complete HTTP response. No cause is attached: transport errors can carry the URL.
 * `status` is set when the failure happened while reading the body, after the headers arrived.
 * `permanent` marks a failure that repeating cannot fix (certificate verification, unknown host, malformed request).
 */
final class ConnectionException extends SiteWarmException
{
    public function __construct(string $message, public readonly bool $timeout = false, public readonly ?int $status = null, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }
}
