<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TaskClaim;
use DomainException;

/**
 * Shared lookup used by every claim-mutating Action (release, heartbeat, complete) so the
 * (issue, pid, capability token) matching logic exists in exactly one place. A worker cannot act
 * on another worker's claim without presenting the exact capability token it was issued.
 */
class AuthorizeAgentClaim
{
    public function handle(int $issueId, int $pid, string $capabilityToken): TaskClaim
    {
        $hash = hash('sha256', $capabilityToken);
        $claim = TaskClaim::query()->where('issue_id', $issueId)->whereNull('released_at')
            ->whereHas('agentSession', fn ($query) => $query->where('capability_token_hash', $hash)->where('pid', $pid))
            ->with('agentSession')->first();
        if (! $claim instanceof TaskClaim) {
            throw new DomainException('No live claim for this task matches that capability token and process.');
        }

        return $claim;
    }
}
