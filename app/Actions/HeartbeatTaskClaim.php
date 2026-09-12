<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TaskClaim;
use App\Services\Process\LinuxProcessLiveness;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Renews a live claim's lease. Re-verifies liveness on every call — a claim whose process has since
 * died (and possibly had its PID reused) cannot renew itself back to life. */
class HeartbeatTaskClaim
{
    public function __construct(private readonly AuthorizeAgentClaim $authorize, private readonly LinuxProcessLiveness $liveness) {}

    public function handle(int $issueId, int $pid, string $capabilityToken, int $minutes = 30): TaskClaim
    {
        if ($minutes < 1 || $minutes > 480) {
            throw new DomainException('A claim renewal length from 1 to 480 minutes is required.');
        }

        return DB::transaction(function () use ($issueId, $pid, $capabilityToken, $minutes): TaskClaim {
            $claim = $this->authorize->handle($issueId, $pid, $capabilityToken);
            $session = $claim->agentSession;

            if ($session->is_verified_live && ! $this->liveness->isAlive($pid, $session->process_started_at)) {
                throw new DomainException('This claim\'s process is no longer verifiably alive; it cannot be renewed.');
            }

            $expiresAt = now()->addMinutes($minutes);
            $session->update(['last_seen_at' => now(), 'expires_at' => $expiresAt]);
            $claim->update(['expires_at' => $expiresAt]);

            return $claim->refresh();
        });
    }
}
