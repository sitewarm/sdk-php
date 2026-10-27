<?php

declare(strict_types=1);

namespace Sitewarm\Sdk;

/** The request did not produce an HTTP response. No cause is attached: transport errors can carry the URL. */
final class ConnectionException extends SiteWarmException
{
    public function __construct(string $message, public readonly bool $timeout = false)
    {
        parent::__construct($message);
    }
}
