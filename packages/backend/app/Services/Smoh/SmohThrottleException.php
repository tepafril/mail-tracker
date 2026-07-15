<?php

declare(strict_types=1);

namespace App\Services\Smoh;

/**
 * SMOH returned HTTP 429. `retryAfter` is the parsed `Retry-After` (seconds), or null
 * if absent — the caller (a queued job) should `release($retryAfter ?? 60)`.
 */
final class SmohThrottleException extends SmohException
{
    public function __construct(public readonly ?int $retryAfter, ?string $body = null)
    {
        parent::__construct('SMOH throttled the request (HTTP 429).', 429, $body);
    }
}
