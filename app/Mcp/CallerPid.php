<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Services\Process\LinuxProcessLiveness;
use Illuminate\Support\Carbon;

/**
 * The PID a claim binds to. Under `docker compose exec` the MCP server's parent is the container's
 * shim, not the agent, so the client passes its own PID in DIBS_CLIENT_PID; it is used only while that
 * process exists, otherwise the server's parent process (correct for a bare `php artisan mcp:start`).
 */
class CallerPid
{
    public const string ENV = 'DIBS_CLIENT_PID';

    public function __construct(private readonly LinuxProcessLiveness $liveness) {}

    public function resolve(): int
    {
        $value = getenv(self::ENV);
        if (is_string($value) && ctype_digit($value) && (int) $value > 0
            && $this->liveness->startedAt((int) $value) instanceof Carbon) {
            return (int) $value;
        }

        return posix_getppid();
    }
}
