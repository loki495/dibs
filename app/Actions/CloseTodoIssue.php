<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordUnavailableException;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

class CloseTodoIssue
{
    public function __construct(private readonly EnqueueGitHubPush $enqueue) {}

    /**
     * Close an issue locally and queue the GitHub close. Retry-safe: the push is keyed per issue,
     * so closing again never queues a second push.
     */
    public function handle(Issue $issue): Issue
    {
        if (! $issue->is_available) {
            throw new TodoRecordUnavailableException("Issue #{$issue->github_number} (local id {$issue->id}) is no longer available; it was removed or lost GitHub access.");
        }

        // attempts: 3 — same transient "database is locked" race against the scheduler's
        // push-queue drain as CompleteTodoTask; see that Action for the observed incident.
        return DB::transaction(function () use ($issue): Issue {
            $issue->update(['state' => 'CLOSED']);
            $this->enqueue->handle('close_issue', 'issue', $issue->id, [], 'issue:close:'.$issue->id);

            return $issue;
        }, 3);
    }
}
