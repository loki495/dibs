<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TaskClaim;

class DescribeTodoClaim
{
    /** @return array<string, mixed>|null */
    public function handle(int $issueId): ?array
    {
        $claim = TaskClaim::query()->where('issue_id', $issueId)->whereNull('released_at')->with('agentSession')->first();
        if (! $claim instanceof TaskClaim) {
            return null;
        }

        $session = $claim->agentSession;

        return [
            'claimId' => $claim->id,
            'issueId' => $claim->issue_id,
            'agentName' => $session->agent_name,
            'pid' => $session->pid,
            'isVerifiedLive' => $session->is_verified_live,
            'lastSeenAt' => $session->last_seen_at->toAtomString(),
            'expiresAt' => $claim->expires_at->toAtomString(),
        ];
    }
}
