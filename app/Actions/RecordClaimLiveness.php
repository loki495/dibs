<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TaskClaim;
use App\Services\Process\LinuxProcessLiveness;

/**
 * Checks every live, verified claim's process and stores the result on the claim, so a process that
 * can't see host PIDs (the web container) can still show it. Run by dibs:claims:watch in the app
 * container. The stored value is display-only: takeover and heartbeat re-check /proc themselves.
 */
class RecordClaimLiveness
{
    public function __construct(private readonly LinuxProcessLiveness $liveness) {}

    /** @return array{alive: int, dead: int, skipped: int} */
    public function handle(): array
    {
        $counts = ['alive' => 0, 'dead' => 0, 'skipped' => 0];
        $claims = TaskClaim::query()->whereNull('released_at')->where('expires_at', '>', now())->with('agentSession')->get();

        foreach ($claims as $claim) {
            $session = $claim->agentSession;
            $alive = $session->is_verified_live && $session->pid !== null && $session->process_started_at !== null
                ? $this->liveness->currentlyAlive($session->pid, $session->process_started_at)
                : null;
            if ($alive === null) {
                $counts['skipped']++;

                continue;
            }

            // A single guarded statement rather than a model save: a claim released between the read
            // above and this write keeps its released state, and updated_at isn't bumped by a check.
            TaskClaim::query()->whereKey($claim->id)->whereNull('released_at')->toBase()
                ->update(['liveness_alive' => $alive, 'liveness_checked_at' => now()]);
            $counts[$alive ? 'alive' : 'dead']++;
        }

        return $counts;
    }
}
