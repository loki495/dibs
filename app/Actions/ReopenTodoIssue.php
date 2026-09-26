<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordUnavailableException;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

class ReopenTodoIssue
{
    public function __construct(private readonly EnqueueGitHubPush $enqueue) {}

    /**
     * Reopen an issue locally and queue the GitHub reopen. Clears state_reason, the same as GitHub
     * itself does on a real reopen — a reason only ever describes how something closed. Retry-safe:
     * the push is keyed per issue, so reopening again never queues a second push.
     */
    public function handle(Issue $issue): Issue
    {
        if (! $issue->is_available) {
            throw new TodoRecordUnavailableException("Issue #{$issue->github_number} (local id {$issue->id}) is no longer available; it was removed or lost GitHub access.");
        }

        // attempts: 3 — same transient "database is locked" race against the scheduler's
        // push-queue drain as CloseTodoIssue; see that Action for the observed incident.
        return DB::transaction(function () use ($issue): Issue {
            $issue->update(['state' => 'OPEN', 'state_reason' => null]);
            $this->enqueue->handle('reopen_issue', 'issue', $issue->id, [], 'issue:reopen:'.$issue->id);

            return $issue;
        }, 3);
    }
}
