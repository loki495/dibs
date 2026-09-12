<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

class ReviseTodoIssue
{
    public function __construct(private readonly ResolveIdempotentWrite $idempotent) {}

    public function handle(
        int $id,
        int $expectedRevision,
        ?string $title = null,
        ?string $body = null,
        ?string $note = null,
        ?string $idempotencyKey = null,
    ): Issue {
        if ($title === null && $body === null) {
            throw new TodoValidationException('Provide a title, a body, or both to revise.');
        }

        $revise = function () use ($id, $expectedRevision, $title, $body, $note): Issue {
            $issue = Issue::query()->find($id);
            if (! $issue instanceof Issue) {
                throw new TodoRecordNotFoundException("No Todo issue with local id [{$id}].");
            }
            if (! $issue->is_available) {
                throw new TodoRecordUnavailableException("Issue #{$issue->github_number} (local id {$id}) is no longer available; it was removed or lost GitHub access.");
            }
            if ($issue->revision !== $expectedRevision) {
                throw new TodoStaleRevisionException($issue->id, "Issue #{$issue->github_number} (local id {$id}) has changed since expectedRevision was read. Reread it and reconcile before retrying.");
            }

            return DB::transaction(function () use ($issue, $title, $body, $note): Issue {
                $changes = array_filter(['title' => $title, 'body' => $body], fn ($value): bool => $value !== null);
                $issue->update([...$changes, 'revision' => $issue->revision + 1]);
                app(EnqueueGitHubPush::class)->handle('update_issue_body', 'issue', $issue->id, ['title' => $issue->title, 'body' => $issue->body], 'issue:revise:'.$issue->id.':'.now()->timestamp);
                if ($note !== null && trim($note) !== '') {
                    app(CreateTodoComment::class)->handle($issue->id, '**Revision note:** '.trim($note));
                }

                return $issue->fresh();
            });
        };

        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $revise();
        }

        return $this->idempotent->handle(
            $idempotencyKey,
            'issue',
            fn (int $subjectId) => Issue::find($subjectId),
            $revise,
        );
    }
}
