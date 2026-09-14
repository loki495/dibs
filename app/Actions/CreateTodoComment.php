<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoValidationException;
use App\Models\Comment;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

class CreateTodoComment
{
    public function __construct(private readonly ResolveIdempotentWrite $idempotent) {}

    public function handle(int $issueId, string $body, ?string $idempotencyKey = null): Comment
    {
        if (trim($body) === '') {
            throw new TodoValidationException('A comment body is required.');
        }

        $create = function () use ($issueId, $body): Comment {
            $issue = Issue::query()->find($issueId);
            if (! $issue instanceof Issue) {
                throw new TodoRecordNotFoundException("No Todo issue with local id [{$issueId}].");
            }
            if (! $issue->is_available) {
                throw new TodoRecordUnavailableException("Issue #{$issue->github_number} (local id {$issueId}) is no longer available; it was removed or lost GitHub access.");
            }

            // attempts: 3 — same transient "database is locked" race against the scheduler's
            // push-queue drain as CompleteTodoTask; see that Action for the observed incident.
            return DB::transaction(function () use ($issue, $body): Comment {
                $comment = Comment::create([
                    'issue_id' => $issue->id,
                    'github_node_id' => null,
                    'body' => trim($body),
                    'revision' => 1,
                    'is_available' => true,
                    'last_seen_at' => now(),
                ]);
                app(EnqueueGitHubPush::class)->handle('create_comment', 'comment', $comment->id, [], 'comment:create:'.$comment->id);

                return $comment;
            }, 3);
        };

        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $create();
        }

        return $this->idempotent->handle(
            $idempotencyKey,
            'comment',
            fn (int $id) => Comment::find($id),
            $create,
        );
    }
}
