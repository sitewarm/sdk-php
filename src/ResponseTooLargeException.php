<?php

declare(strict_types=1);

namespace Sitewarm\Sdk;

/** The response body was larger than the SDK reads (32 MiB). Not retried. */
final class ResponseTooLargeException extends SiteWarmException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("response body is larger than {$limit} bytes");
    }
}
