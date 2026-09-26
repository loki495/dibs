<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

class CloseTodoIssue
{
    /** GitHub's own IssueClosedStateReason enum values. */
    public const string REASON_COMPLETED = 'COMPLETED';

    public const string REASON_NOT_PLANNED = 'NOT_PLANNED';

    public const array REASONS = [self::REASON_COMPLETED, self::REASON_NOT_PLANNED];

    public function __construct(
        private readonly EnqueueGitHubPush $enqueue,
        private readonly CreateClosingComment $createClosingComment,
    ) {}

    /**
     * Close an issue locally and queue the GitHub close, carrying an optional reason (self::REASONS)
     * and an optional closing note/references. Retry-safe: the close push is keyed per issue, so
     * closing again never queues a second push, and never creates a second closing comment for an
     * issue that was already closed.
     *
     * @param  list<string>  $references
     */
    public function handle(Issue $issue, ?string $reason = null, ?string $note = null, array $references = []): Issue
    {
        if (! $issue->is_available) {
            throw new TodoRecordUnavailableException("Issue #{$issue->github_number} (local id {$issue->id}) is no longer available; it was removed or lost GitHub access.");
        }

        if ($reason !== null && ! in_array($reason, self::REASONS, true)) {
            throw new TodoValidationException('Reason must be one of: '.implode(', ', self::REASONS).'.');
        }

        // attempts: 3 — same transient "database is locked" race against the scheduler's
        // push-queue drain as CompleteTodoTask; see that Action for the observed incident.
        return DB::transaction(function () use ($issue, $reason, $note, $references): Issue {
            $wasAlreadyClosed = $issue->state === 'CLOSED';
            $issue->update(['state' => 'CLOSED', 'state_reason' => $reason, 'closed_at' => $wasAlreadyClosed ? ($issue->closed_at ?? now()) : now()]);
            $this->enqueue->handle('close_issue', 'issue', $issue->id, ['stateReason' => $reason], 'issue:close:'.$issue->id);

            if (! $wasAlreadyClosed) {
                $this->createClosingComment->handle($issue->id, $note, $references);
            }

            return $issue;
        }, 3);
    }
}
