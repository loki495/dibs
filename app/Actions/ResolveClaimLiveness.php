<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TaskClaim;
use App\Services\Process\LinuxProcessLiveness;

/**
 * A claim's liveness as this process sees it: a direct /proc check where host PIDs are visible
 * (isCurrentlyAlive, null otherwise), plus the last result dibs:claims:watch recorded.
 * displayAlive falls back to a fresh recording when the direct check isn't possible; it's for
 * showing to people only, never for deciding a takeover or heartbeat.
 */
class ResolveClaimLiveness
{
    public function __construct(private readonly LinuxProcessLiveness $liveness) {}

    /**
     * @return array{isCurrentlyAlive: bool|null, recordedLiveness: array{alive: bool, checkedAt: string, isStale: bool}|null, displayAlive: bool|null}
     */
    public function handle(TaskClaim $claim): array
    {
        $session = $claim->agentSession;
        $isCurrentlyAlive = $session->is_verified_live && $session->pid !== null && $session->process_started_at !== null
            ? $this->liveness->currentlyAlive($session->pid, $session->process_started_at)
            : null;

        $recorded = null;
        if ($claim->liveness_alive !== null && $claim->liveness_checked_at !== null) {
            $staleAfter = max(1, (int) config('dibs.claim_liveness.stale_after_seconds'));
            $recorded = [
                'alive' => $claim->liveness_alive,
                'checkedAt' => $claim->liveness_checked_at->toAtomString(),
                'isStale' => $claim->liveness_checked_at->lt(now()->subSeconds($staleAfter)),
            ];
        }

        $displayAlive = $isCurrentlyAlive ?? ($recorded !== null && ! $recorded['isStale'] ? $recorded['alive'] : null);

        return ['isCurrentlyAlive' => $isCurrentlyAlive, 'recordedLiveness' => $recorded, 'displayAlive' => $displayAlive];
    }
}
