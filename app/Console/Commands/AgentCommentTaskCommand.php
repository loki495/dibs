<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateTodoComment;
use App\Actions\ReviseTodoComment;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\Comment;
use Illuminate\Console\Command;

class AgentCommentTaskCommand extends Command
{
    protected $signature = 'todo:agent:comment
        {issue? : Local Todo issue ID (required unless --comment-id is given)}
        {--body= : Comment body}
        {--comment-id= : Edit this existing comment instead of adding a new one}
        {--expected-revision= : Required when editing; the revision last read for this comment}
        {--idempotency-key= : Retrying the same key returns the original result}';

    protected $description = 'Add or edit a Todo task comment for a host-local agent as JSON';

    public function handle(CreateTodoComment $create, ReviseTodoComment $revise): int
    {
        $body = $this->option('body');
        if ($body === null) {
            $this->error('--body is required.');

            return self::FAILURE;
        }

        $commentId = $this->option('comment-id');
        try {
            if ($commentId !== null) {
                $expectedRevision = $this->option('expected-revision');
                if ($expectedRevision === null || ! ctype_digit((string) $expectedRevision)) {
                    $this->error('A numeric --expected-revision is required when editing a comment.');

                    return self::FAILURE;
                }
                $comment = $revise->handle(
                    id: (int) $commentId,
                    body: $body,
                    expectedRevision: (int) $expectedRevision,
                    idempotencyKey: $this->option('idempotency-key'),
                );
            } else {
                if ($this->argument('issue') === null) {
                    $this->error('Provide the issue argument to add a comment, or --comment-id and --expected-revision to edit one.');

                    return self::FAILURE;
                }
                $comment = $create->handle(
                    issueId: (int) $this->argument('issue'),
                    body: $body,
                    idempotencyKey: $this->option('idempotency-key'),
                );
            }
        } catch (TodoStaleRevisionException $exception) {
            $this->line(json_encode(['conflict' => true, 'message' => $exception->getMessage(), 'current' => $this->describeComment(Comment::findOrFail($exception->currentId))], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (TodoRecordNotFoundException|TodoRecordUnavailableException|TodoValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode(['conflict' => false] + $this->describeComment($comment), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function describeComment(Comment $comment): array
    {
        return ['comment_id' => $comment->id, 'issue_id' => $comment->issue_id, 'body' => $comment->body, 'revision' => $comment->revision];
    }
}
