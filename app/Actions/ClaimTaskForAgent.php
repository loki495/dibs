<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AgentSession;
use App\Models\Issue;
use App\Models\TaskClaim;
use App\Services\Process\LinuxProcessLiveness;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hardened claim acquisition — see #37/#40/#56 (loki495/Todo). Binds a claim to the caller's own
 * (pid, process start time) rather than trusting a self-reported session string: the server reads
 * /proc itself to confirm the pid is real right now, then re-checks that same fact before ever
 * treating an existing claim as abandoned. A capability token (returned once, in plaintext, only
 * here) is required to release or renew the claim later — see AuthorizeAgentClaim.
 */
class ClaimTaskForAgent
{
    public function __construct(private readonly LinuxProcessLiveness $liveness) {}

    /** @return array{claim: TaskClaim, capability_token: string, is_verified_live: bool} */
    public function handle(Issue $issue, string $agentName, int $pid, int $minutes = 30): array
    {
        $agentName = trim($agentName);
        if (! $issue->is_available || $agentName === '' || $pid < 1 || $minutes < 1 || $minutes > 480) {
            throw new DomainException('A current task, agent name, a real process ID, and a claim length from 1 to 480 minutes are required.');
        }

        return DB::transaction(function () use ($issue, $agentName, $pid, $minutes): array {
            $this->releaseIfStaleOrDead($issue);

            $active = TaskClaim::query()->where('issue_id', $issue->id)->whereNull('released_at')->exists();
            if ($active) {
                throw new DomainException('This task is currently claimed by another agent session.');
            }

            $startedAt = $this->liveness->startedAt($pid);
            $isVerifiedLive = $startedAt instanceof Carbon;
            $token = Str::random(40);
            $expiresAt = now()->addMinutes($minutes);

            $session = AgentSession::query()->create([
                'agent_name' => $agentName,
                'session_key' => (string) Str::uuid(),
                'host_identifier' => gethostname() ?: null,
                'pid' => $pid,
                'process_started_at' => $startedAt,
                'capability_token_hash' => hash('sha256', $token),
                'is_verified_live' => $isVerifiedLive,
                'last_seen_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $claim = TaskClaim::query()->create([
                'issue_id' => $issue->id, 'agent_session_id' => $session->id, 'expires_at' => $expiresAt,
            ]);

            return ['claim' => $claim, 'capability_token' => $token, 'is_verified_live' => $isVerifiedLive];
        });
    }

    /**
     * Auto-releases an existing claim on this issue if its lease already expired, or — the actual
     * hardening — if its session claimed verified liveness but that exact (pid, start time) no longer
     * exists (the process died and its PID may since have been reused for something unrelated).
     * A claim whose liveness was never verifiable in the first place (weaker-assurance mode) is left
     * alone here; only an explicit lease expiry or a confirmed-dead verified process force a takeover.
     */
    private function releaseIfStaleOrDead(Issue $issue): void
    {
        $active = TaskClaim::query()->where('issue_id', $issue->id)->whereNull('released_at')->with('agentSession')->first();
        if (! $active instanceof TaskClaim) {
            return;
        }
        if ($active->expires_at->isPast()) {
            $active->update(['released_at' => now()]);

            return;
        }
        $session = $active->agentSession;
        if ($session->is_verified_live && $session->pid !== null && $session->process_started_at !== null
            && ! $this->liveness->isAlive($session->pid, $session->process_started_at)) {
            $active->update(['released_at' => now()]);
        }
    }
}
