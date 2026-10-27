<?php

declare(strict_types=1);

namespace Sitewarm\Sdk;

/** Base class of every error the SDK throws. Messages never contain the API key. */
class SiteWarmException extends \RuntimeException
{
}
