<?php

declare(strict_types=1);

namespace Sitewarm\Sdk;

/** A 2xx response body was not valid JSON (for example an HTML page from a proxy). Never includes the body. */
final class DecodeException extends SiteWarmException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct("invalid JSON in {$status} response");
    }
}
