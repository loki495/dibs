<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ChangeLog;
use App\Models\McpCallLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deletes activity-log rows older than each log's configured retention (config('dibs.activity')).
 * A retention of 0 or less keeps that log forever. A row exactly at the cutoff is kept.
 */
class PruneActivityLog
{
    /** @return array{mcp: int, changes: int} rows deleted from each log */
    public function handle(): array
    {
        return [
            'mcp' => $this->prune(McpCallLog::query(), (int) config('dibs.activity.mcp_retention_days')),
            'changes' => $this->prune(ChangeLog::query(), (int) config('dibs.activity.change_retention_days')),
        ];
    }

    /** @param  Builder<McpCallLog>|Builder<ChangeLog>  $query */
    private function prune(Builder $query, int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return $query->where('created_at', '<', now()->subDays($days))->delete();
    }
}
