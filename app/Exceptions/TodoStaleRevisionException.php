<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The caller's expected revision no longer matches the current local record —
 * someone else (human or another agent) revised it since the caller last read
 * it. Carries the current record so the caller can reread and reconcile
 * instead of just being told "no."
 */
class TodoStaleRevisionException extends RuntimeException
{
    public function __construct(public readonly int $currentId, string $message)
    {
        parent::__construct($message);
    }
}
