<?php

declare(strict_types=1);

namespace Sitewarm\Sdk;

/** Hard limits of the client. */
final class Limits
{
    /** Largest response body that is read (32 MiB). */
    public const MAX_BODY = 32 * 1024 * 1024;
    public const MAX_PAGES = 10_000;
}
