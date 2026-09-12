<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AgentSession;
use App\Models\Issue;
use App\Models\TaskClaim;
use DomainException;
use Illuminate\Support\Facades\DB;

class ClaimTaskForAgent
{
    public function handle(Issue $issue, string $agentName, string $sessionKey, int $minutes = 30): TaskClaim
    {
        $agentName = trim($agentName);
        $sessionKey = trim($sessionKey);
        if (! $issue->is_available || $agentName === '' || $sessionKey === '' || $minutes < 1 || $minutes > 480) {
            throw new DomainException('A current task, agent name, session key, and claim length from 1 to 480 minutes are required.');
        }

        return DB::transaction(function () use ($issue, $agentName, $sessionKey, $minutes): TaskClaim {
            $expiresAt = now()->addMinutes($minutes);
            $session = AgentSession::query()->updateOrCreate(['session_key' => $sessionKey], [
                'agent_name' => $agentName, 'last_seen_at' => now(), 'expires_at' => $expiresAt,
            ]);
            TaskClaim::query()->where('issue_id', $issue->id)->whereNull('released_at')->where('expires_at', '<=', now())->update(['released_at' => now()]);
            $claim = TaskClaim::query()->where('issue_id', $issue->id)->whereNull('released_at')->first();
            if ($claim instanceof TaskClaim && $claim->agent_session_id !== $session->id) {
                throw new DomainException('This task is currently claimed by another agent session.');
            }
            if ($claim instanceof TaskClaim) {
                $claim->update(['expires_at' => $expiresAt]);

                return $claim->refresh();
            }

            return TaskClaim::query()->create(['issue_id' => $issue->id, 'agent_session_id' => $session->id, 'expires_at' => $expiresAt]);
        });
    }
}
