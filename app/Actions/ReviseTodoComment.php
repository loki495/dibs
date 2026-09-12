<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\Comment;
use Illuminate\Support\Facades\DB;

class ReviseTodoComment
{
    public function __construct(private readonly ResolveIdempotentWrite $idempotent) {}

    public function handle(int $id, string $body, int $expectedRevision, ?string $idempotencyKey = null): Comment
    {
        if (trim($body) === '') {
            throw new TodoValidationException('A comment body is required.');
        }

        $revise = function () use ($id, $body, $expectedRevision): Comment {
            $comment = Comment::query()->find($id);
            if (! $comment instanceof Comment) {
                throw new TodoRecordNotFoundException("No comment with local id [{$id}].");
            }
            if (! $comment->is_available) {
                throw new TodoRecordUnavailableException("Comment (local id {$id}) is no longer available; it was removed or lost GitHub access.");
            }
            if ($comment->revision !== $expectedRevision) {
                throw new TodoStaleRevisionException($comment->id, "Comment (local id {$id}) has changed since expectedRevision was read. Reread it and reconcile before retrying.");
            }

            return DB::transaction(function () use ($comment, $body): Comment {
                $comment->update(['body' => trim($body), 'revision' => $comment->revision + 1]);
                app(EnqueueGitHubPush::class)->handle('update_comment', 'comment', $comment->id, [], 'comment:update:'.$comment->id.':'.now()->timestamp);

                return $comment->fresh();
            });
        };

        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $revise();
        }

        return $this->idempotent->handle(
            $idempotencyKey,
            'comment',
            fn (int $subjectId) => Comment::find($subjectId),
            $revise,
        );
    }
}
