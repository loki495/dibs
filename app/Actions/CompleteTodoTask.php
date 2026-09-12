<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordUnavailableException;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Completing a task is claim-scoped, unlike the workspace UI's closeIssue() (any authenticated human
 * can close any issue there) — only the exact (issue, pid, capability token) that holds the live
 * claim can complete it: the active worker can maintain and finish its claim, but another worker
 * cannot impersonate it.
 */
class CompleteTodoTask
{
    public function __construct(
        private readonly AuthorizeAgentClaim $authorize,
        private readonly CreateTodoComment $comment,
    ) {}

    public function handle(int $issueId, int $pid, string $capabilityToken, ?string $summary = null): Issue
    {
        return DB::transaction(function () use ($issueId, $pid, $capabilityToken, $summary): Issue {
            $claim = $this->authorize->handle($issueId, $pid, $capabilityToken);
            $issue = $claim->issue;
            if (! $issue->is_available) {
                throw new TodoRecordUnavailableException("Issue #{$issue->github_number} (local id {$issueId}) is no longer available; it was removed or lost GitHub access.");
            }

            $issue->update(['state' => 'CLOSED']);
            app(EnqueueGitHubPush::class)->handle('close_issue', 'issue', $issue->id, [], 'issue:close:'.$issue->id);
            if ($summary !== null && trim($summary) !== '') {
                $this->comment->handle($issue->id, '**Completed:** '.trim($summary));
            }
            $claim->update(['released_at' => now()]);

            return $issue->fresh();
        });
    }
}
