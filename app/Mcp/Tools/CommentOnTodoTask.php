<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CreateTodoComment;
use App\Actions\ReviseTodoComment;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Mcp\Tools\Concerns\ReplaceArgument;
use App\Models\Comment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Adds or edits a comment — supporting discussion, evidence, or a checkpoint, never the canonical document itself (use todo_revise for that). Pass issueId to add a new comment. Pass commentId and expectedRevision (the `revision` last read for that comment) to edit an existing one, with a whole new `body` or with `replace` exact-string edits of its current text (cheapest for a small change such as an atlas stamp; the response then omits the body); a stale expectedRevision returns {conflict: true, current: <the comment now>} instead of applying anything or erroring.')]
class CommentOnTodoTask extends Tool implements Errable
{
    protected string $name = 'todo_comment';

    public function handle(Request $request, CreateTodoComment $create, ReviseTodoComment $revise): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'issueId' => ['sometimes', 'integer', 'min:1'],
            'commentId' => ['sometimes', 'integer', 'min:1'],
            'expectedRevision' => ['sometimes', 'integer', 'min:1'],
            'body' => ['required_without:replace', 'string', 'max:65535'],
            'replace' => ['sometimes', 'array', 'min:1', 'max:50'],
            'replace.*.from' => ['required', 'string', 'max:65535'],
            'replace.*.to' => ['present', 'nullable', 'string', 'max:65535'],
            'replace.*.all' => ['sometimes', 'boolean'],
            'idempotencyKey' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            if (isset($arguments['commentId'])) {
                if (! isset($arguments['expectedRevision'])) {
                    throw new TodoValidationException('expectedRevision is required when editing a comment.');
                }
                $comment = $revise->handle(
                    id: $arguments['commentId'],
                    body: $arguments['body'] ?? null,
                    expectedRevision: $arguments['expectedRevision'],
                    idempotencyKey: $arguments['idempotencyKey'] ?? null,
                    replace: ReplaceArgument::normalize($arguments['replace'] ?? []),
                );
            } else {
                if (isset($arguments['replace'])) {
                    throw new TodoValidationException('replace edits an existing comment: pass commentId and expectedRevision.');
                }
                if (! isset($arguments['issueId'])) {
                    throw new TodoValidationException('Provide issueId to add a comment, or commentId and expectedRevision to edit one.');
                }
                $comment = $create->handle(
                    issueId: $arguments['issueId'],
                    body: $arguments['body'] ?? '',
                    idempotencyKey: $arguments['idempotencyKey'] ?? null,
                );
            }
        } catch (TodoStaleRevisionException $exception) {
            return Response::structured([
                'conflict' => true,
                'message' => $exception->getMessage(),
                'current' => $this->describeComment(Comment::findOrFail($exception->currentId)),
            ]);
        } catch (TodoRecordNotFoundException|TodoRecordUnavailableException|TodoValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        $result = ['conflict' => false] + $this->describeComment($comment);
        if (isset($arguments['replace'])) {
            unset($result['body']);
        }

        return Response::structured($result);
    }

    /** @return array<string, mixed> */
    private function describeComment(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'issueId' => $comment->issue_id,
            'body' => $comment->body,
            'revision' => $comment->revision,
        ];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issueId' => $schema->integer()->description('The local issue id to comment on. Required when adding a new comment.'),
            'commentId' => $schema->integer()->description('An existing comment\'s local id, to edit it instead of adding a new one.'),
            'expectedRevision' => $schema->integer()->description('The `revision` last read for this comment. Required when commentId is set.'),
            'body' => $schema->string()->description('The comment text. Required unless `replace` is given.'),
            'replace' => ReplaceArgument::schema($schema, 'comment'),
            'idempotencyKey' => $schema->string()->nullable()->description('A caller-chosen key; retrying the same key returns the original result instead of duplicating the write.'),
        ];
    }
}
