<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * $transient: retrying the same call later may succeed (network, 5xx, rate limit, an
 * unconfirmed response). $retryAt: GitHub said when to retry (a rate limit's Retry-After or
 * X-RateLimit-Reset); null leaves the delay to the caller's own backoff.
 */
class GitHubSyncException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retrySeconds = 60,
        public readonly bool $transient = true,
        public readonly ?CarbonImmutable $retryAt = null,
    ) {
        parent::__construct($message);
    }

    public static function rateLimited(string $message, CarbonImmutable $retryAt): self
    {
        return new self($message, max(60, (int) ceil(CarbonImmutable::now()->diffInSeconds($retryAt))), true, $retryAt);
    }

    public static function permanent(string $message): self
    {
        return new self($message, transient: false);
    }

    public function isRateLimit(): bool
    {
        return $this->retryAt instanceof CarbonImmutable;
    }
}
