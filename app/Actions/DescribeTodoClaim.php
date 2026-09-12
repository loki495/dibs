<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TaskClaim;
use App\Services\Process\LinuxProcessLiveness;

class DescribeTodoClaim
{
    public function __construct(private readonly LinuxProcessLiveness $liveness) {}

    /** @return array<string, mixed>|null */
    public function handle(int $issueId): ?array
    {
        $claim = TaskClaim::query()->where('issue_id', $issueId)->whereNull('released_at')->with('agentSession')->first();
        if (! $claim instanceof TaskClaim) {
            return null;
        }

        $session = $claim->agentSession;
        $isCurrentlyAlive = $session->is_verified_live && $session->pid !== null && $session->process_started_at !== null
            ? $this->liveness->isAlive($session->pid, $session->process_started_at)
            : null;

        return [
            'claimId' => $claim->id,
            'issueId' => $claim->issue_id,
            'agentName' => $session->agent_name,
            'host' => $session->host_identifier,
            'pid' => $session->pid,
            'processStartedAt' => $session->process_started_at?->toAtomString(),
            'isVerifiedLive' => $session->is_verified_live,
            'isCurrentlyAlive' => $isCurrentlyAlive,
            'isExpired' => $claim->expires_at->isPast(),
            'lastSeenAt' => $session->last_seen_at->toAtomString(),
            'expiresAt' => $claim->expires_at->toAtomString(),
        ];
    }
}
