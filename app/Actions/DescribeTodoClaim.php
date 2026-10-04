<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TaskClaim;

class DescribeTodoClaim
{
    public function __construct(private readonly ResolveClaimLiveness $liveness) {}

    /** @return array<string, mixed>|null */
    public function handle(int $issueId): ?array
    {
        $claim = TaskClaim::query()->where('issue_id', $issueId)->whereNull('released_at')->with('agentSession')->first();
        if (! $claim instanceof TaskClaim) {
            return null;
        }

        $session = $claim->agentSession;
        $liveness = $this->liveness->handle($claim);

        return [
            'claimId' => $claim->id,
            'issueId' => $claim->issue_id,
            'agentName' => $session->agent_name,
            'host' => $session->host_identifier,
            'pid' => $session->pid,
            'processStartedAt' => $session->process_started_at?->toAtomString(),
            'isVerifiedLive' => $session->is_verified_live,
            'isCurrentlyAlive' => $liveness['isCurrentlyAlive'],
            'recordedLiveness' => $liveness['recordedLiveness'],
            'displayAlive' => $liveness['displayAlive'],
            'isExpired' => $claim->expires_at->isPast(),
            'lastSeenAt' => $session->last_seen_at->toAtomString(),
            'expiresAt' => $claim->expires_at->toAtomString(),
        ];
    }
}
