<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PruneActivityLog;
use Illuminate\Console\Command;

/** Applies the activity-log retention settings; scheduled daily in routes/console.php. */
class PruneActivityLogCommand extends Command
{
    protected $signature = 'activity:prune';

    protected $description = 'Delete MCP call log and change log rows older than their configured retention';

    public function handle(PruneActivityLog $prune): int
    {
        if ((int) config('dibs.activity.mcp_retention_days') <= 0 && (int) config('dibs.activity.change_retention_days') <= 0) {
            $this->info('Retention is disabled for both logs (0 days) -- nothing to prune.');

            return self::SUCCESS;
        }

        $deleted = $prune->handle();

        $this->info("Pruned {$deleted['mcp']} MCP call log row(s) and {$deleted['changes']} change log row(s).");

        return self::SUCCESS;
    }
}
