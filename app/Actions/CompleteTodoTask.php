<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Completing a task is claim-scoped, unlike the workspace UI's closeIssue() (any authenticated human
 * can close any issue there) — only the exact (issue, pid, capability token) that holds the live
 * claim can complete it: the active worker can maintain and finish its claim, but another worker
 * cannot impersonate it. `summary` is the closing note; see CloseTodoIssue for `reason` and
 * `references`.
 */
class CompleteTodoTask
{
    public function __construct(
        private readonly AuthorizeAgentClaim $authorize,
        private readonly CloseTodoIssue $close,
    ) {}

    /** @param  list<string>  $references */
    public function handle(int $issueId, int $pid, string $capabilityToken, ?string $summary = null, ?string $reason = null, array $references = []): Issue
    {
        // attempts: 3 — observed twice in production logs (2026-09-13/14) as a transient
        // "database is locked" on the issues update, racing the scheduler's push-queue drain
        // against this same SQLite file. Laravel's transaction() retries automatically on
        // exactly this error string (see ConcurrencyErrorDetector); a bare attempts:1 let it
        // surface to the calling agent as an opaque "internal server error".
        return DB::transaction(function () use ($issueId, $pid, $capabilityToken, $summary, $reason, $references): Issue {
            $claim = $this->authorize->handle($issueId, $pid, $capabilityToken);
            $issue = $this->close->handle($claim->issue, reason: $reason, note: $summary, references: $references);
            $claim->update(['released_at' => now()]);

            return $issue->fresh();
        }, 3);
    }
}
