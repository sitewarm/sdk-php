<?php

declare(strict_types=1);

namespace Sitewarm\Sdk;

/** Holds the API key outside the object's properties, so no default dump of the client can show it. */
final class Secret
{
    /** @var \WeakMap<Secret, string>|null */
    private static ?\WeakMap $store = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::$store ??= new \WeakMap();
        self::$store[$this] = $value;
    }

    public function reveal(): string
    {
        return self::$store[$this] ?? '';
    }

    public function __toString(): string
    {
        return '[redacted]';
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('the API key cannot be serialized');
    }

    private function __clone()
    {
    }
}
