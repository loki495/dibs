<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChangeLog;
use App\Models\McpCallLog;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/** The two activity logs by name, so every Action that takes a log name validates it the same way. */
final class ActivityLog
{
    public const string MCP_CALLS = 'mcp';

    public const string CHANGES = 'changes';

    /** @return Builder<McpCallLog>|Builder<ChangeLog> */
    public static function query(string $log): Builder
    {
        return match ($log) {
            self::MCP_CALLS => McpCallLog::query(),
            self::CHANGES => ChangeLog::query(),
            default => throw new InvalidArgumentException(sprintf('Unknown activity log "%s"', $log)),
        };
    }
}
