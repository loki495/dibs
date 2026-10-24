<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use App\Models\TaskClaim;
use App\Services\Process\LinuxProcessLiveness;
use App\Support\TrustedAuthors;

/**
 * The first step of a plan, in plan order, that is open, not held by a live claim and not blocked (every blocked-by
 * step closed), with its work-order preview. A claim whose lease expired or whose verified process is gone counts as
 * free, the same test todo_claim applies before a takeover. When nothing is ready it says why and lists what waits.
 */
class FindNextPlanStep
{
    public function __construct(
        private readonly LinuxProcessLiveness $liveness,
        private readonly TrustedAuthors $trust,
        private readonly DescribeWorkOrder $workOrder,
    ) {}

    /** @return array{ready: array<string, mixed>|null, reason: ?string, waiting: list<array<string, mixed>>} */
    public function handle(int $planId): array
    {
        $plan = Issue::query()->where('is_available', true)->find($planId);
        if (! $plan instanceof Issue) {
            throw new TodoRecordNotFoundException("No available Todo issue with local id [{$planId}].");
        }

        $steps = $plan->children()->where('is_available', true)
            ->withCount(['children' => fn ($query) => $query->where('is_available', true)])
            ->with(['blockedBy' => fn ($query) => $query->where('is_available', true)])
            ->get()
            ->filter(fn (Issue $step): bool => $step->children_count === 0 && $this->trust->allows($step->author_login));
        if ($steps->isEmpty()) {
            throw new TodoValidationException("Issue {$planId} has no steps (child tasks) to pick from.");
        }

        $waiting = [];
        $claimed = 0;
        $blocked = 0;
        foreach ($steps->where('state', 'OPEN') as $step) {
            $openBlockers = $step->blockedBy->where('state', 'OPEN')->pluck('id')->values()->all();
            $claimedBy = $this->liveClaimant($step);
            if ($openBlockers === [] && $claimedBy === null) {
                return ['ready' => $this->workOrder->handle($step), 'reason' => null, 'waiting' => $waiting];
            }
            $claimed += $claimedBy === null ? 0 : 1;
            $blocked += $openBlockers === [] ? 0 : 1;
            $waiting[] = array_filter([
                'id' => $step->id,
                'title' => $this->trust->title($step),
                'claimedBy' => $claimedBy,
                'blockedBy' => $openBlockers === [] ? null : $openBlockers,
            ], fn (mixed $value): bool => $value !== null);
        }

        $total = count($waiting);
        $reason = match (true) {
            $total === 0 => 'plan_done',
            $claimed === $total => 'all_claimed',
            $blocked === $total => 'blocked',
            default => 'mixed',
        };

        return ['ready' => null, 'reason' => $reason, 'waiting' => $waiting];
    }

    private function liveClaimant(Issue $step): ?string
    {
        $claim = TaskClaim::query()->where('issue_id', $step->id)->whereNull('released_at')->with('agentSession')->first();
        if (! $claim instanceof TaskClaim || $claim->expires_at->isPast()) {
            return null;
        }
        $session = $claim->agentSession;
        if ($session->is_verified_live && $session->pid !== null && $session->process_started_at !== null
            && $this->liveness->currentlyAlive($session->pid, $session->process_started_at) === false) {
            return null;
        }

        return $session->agent_name;
    }
}
