<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use App\Services\Activity\ActivityRecorder;
use App\Support\RealReason;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Completing a task is claim-scoped, unlike the workspace UI's closeIssue() (any authenticated human
 * can close any issue there) — only the exact (issue, pid, capability token) that holds the live
 * claim can complete it: the active worker can maintain and finish its claim, but another worker
 * cannot impersonate it. `summary` is the closing note; see CloseTodoIssue for `reason` and
 * `references`. The completion guards (CheckCompletionGuards) refuse a completion that lacks the
 * evidence its profile asks for, unless `force` comes with a real `forceReason`; an override is
 * logged in the activity log and noted in the closing note.
 */
class CompleteTodoTask
{
    public function __construct(
        private readonly AuthorizeAgentClaim $authorize,
        private readonly CloseTodoIssue $close,
        private readonly CheckCompletionGuards $guards,
        private readonly ActivityRecorder $recorder,
    ) {}

    public const array RESULT_KEYS = ['commit', 'tests', 'docs', 'done_when_held'];

    /**
     * @param  list<string>  $references
     * @param  array<string, mixed>  $result  {commit, tests, docs, done_when_held}
     */
    public function handle(int $issueId, int $pid, string $capabilityToken, ?string $summary = null, ?string $reason = null, array $references = [], array $result = [], bool $force = false, ?string $forceReason = null): Issue
    {
        $result = Arr::only($result, self::RESULT_KEYS);
        if ($force && ($problem = RealReason::problem($forceReason)) !== null) {
            throw new TodoValidationException("force needs a real reason: {$problem}.");
        }

        // attempts: 3 — observed twice in production logs (2026-09-13/14) as a transient
        // "database is locked" on the issues update, racing the scheduler's push-queue drain
        // against this same SQLite file. Laravel's transaction() retries automatically on
        // exactly this error string (see ConcurrencyErrorDetector); a bare attempts:1 let it
        // surface to the calling agent as an opaque "internal server error".
        return DB::transaction(function () use ($issueId, $pid, $capabilityToken, $summary, $reason, $references, $result, $force, $forceReason): Issue {
            $claim = $this->authorize->handle($issueId, $pid, $capabilityToken);
            $guard = $claim->issue->is_available ? $this->guards->handle($claim->issue, $reason, $summary, $result) : ['profile' => '', 'missing' => []];
            if ($guard['missing'] !== []) {
                if (! $force) {
                    throw new TodoValidationException("Completion refused (guard profile {$guard['profile']}):\n- ".implode("\n- ", $guard['missing'])
                        ."\nAdd what is missing and call todo_complete again, or pass force: true with a forceReason saying why this evidence does not apply.");
                }
                $this->recorder->change('CompleteTodoTask', $claim->issue, 'Guard overridden: '.trim((string) $forceReason), ['guard_override' => ['from' => $guard['missing'], 'to' => trim((string) $forceReason)]]);
                $summary = trim(trim((string) $summary)."\n\nGuard overridden: ".trim((string) $forceReason));
            }
            $issue = $this->close->handle($claim->issue, reason: $reason, note: $summary, references: $references, result: $result);
            $claim->update(['released_at' => now()]);

            return $issue->fresh();
        }, 3);
    }
}
