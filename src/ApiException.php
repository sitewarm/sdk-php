<?php

declare(strict_types=1);

namespace Sitewarm\Sdk;

/** A non-2xx answer. `problem` is the RFC 9457 body (or a synthesized one when the body was not a problem). */
final class ApiException extends SiteWarmException
{
    public const AUTHENTICATION = 'authentication';
    public const PERMISSION = 'permission';
    public const NOT_FOUND = 'not_found';
    public const CONFLICT = 'conflict';
    public const VALIDATION = 'validation';
    public const RATE_LIMITED = 'rate_limited';
    public const SERVER = 'server';
    public const OTHER = 'other';

    /**
     * @param array<string, mixed> $problem
     */
    public function __construct(
        public readonly int $status,
        public readonly array $problem,
        public readonly ?string $errorCode,
        public readonly ?float $retryAfter,
        public readonly ?int $rateLimitRemaining,
    ) {
        $title = is_string($problem['title'] ?? null) ? $problem['title'] : 'HTTP ' . $status;
        $detail = is_string($problem['detail'] ?? null) ? ': ' . $problem['detail'] : '';
        parent::__construct("sitewarm API {$status} {$title}{$detail}", $status);
    }

    public function kind(): string
    {
        return match (true) {
            $this->status === 401 => self::AUTHENTICATION,
            $this->status === 403 => self::PERMISSION,
            $this->status === 404 => self::NOT_FOUND,
            $this->status === 409 => self::CONFLICT,
            $this->status === 422 => self::VALIDATION,
            $this->status === 429 => self::RATE_LIMITED,
            $this->status >= 500 => self::SERVER,
            default => self::OTHER,
        };
    }
}
