<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use RuntimeException;

class GitHubSyncException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retrySeconds = 60)
    {
        parent::__construct($message);
    }
}
