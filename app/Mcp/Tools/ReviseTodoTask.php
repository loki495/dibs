<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoIssue;
use App\Actions\ReviseTodoIssue;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Revises an issue\'s canonical title, body, Group (groupId, newGroupName or clearGroup), parent (parentId or clearParent), and/or labels (addLabels/removeLabels deltas by name) — optimistic concurrency against local SQLite, not GitHub: pass the `revision` you last read from todo_show/todo_list as expectedRevision. If the issue changed since then, this returns {conflict: true, current: <fresh todo_show detail>} instead of applying anything or erroring, so you can reread and reconcile. groupId moves the issue to a different Group within its existing area (the issue must already belong to an area/Project — set one via todo_create/todo_scaffold_plan first); use clearGroup to remove it. Add an optional concise note to record why, without duplicating the whole body into a comment. This is for the canonical document itself — use todo_comment for supporting discussion/evidence.')]
class ReviseTodoTask extends Tool implements Errable
{
    protected string $name = 'todo_revise';

    public function handle(Request $request, ReviseTodoIssue $revise, DescribeTodoIssue $describe): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'groupId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'newGroupName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'clearGroup' => ['sometimes', 'boolean'],
            'parentId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'clearParent' => ['sometimes', 'boolean'],
            'addLabels' => ['sometimes', 'array', 'max:20'],
            'addLabels.*' => ['string', 'max:100'],
            'removeLabels' => ['sometimes', 'array', 'max:20'],
            'removeLabels.*' => ['string', 'max:100'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'idempotencyKey' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $issue = $revise->handle(
                id: $arguments['id'],
                expectedRevision: $arguments['expectedRevision'],
                title: $arguments['title'] ?? null,
                body: $arguments['body'] ?? null,
                groupId: $arguments['groupId'] ?? null,
                note: $arguments['note'] ?? null,
                idempotencyKey: $arguments['idempotencyKey'] ?? null,
                addLabels: $arguments['addLabels'] ?? [],
                removeLabels: $arguments['removeLabels'] ?? [],
                parentId: $arguments['parentId'] ?? null,
                clearParent: $arguments['clearParent'] ?? false,
                newGroupName: $arguments['newGroupName'] ?? null,
                clearGroup: $arguments['clearGroup'] ?? false,
            );
        } catch (TodoStaleRevisionException $exception) {
            return Response::structured([
                'conflict' => true,
                'message' => $exception->getMessage(),
                'current' => $describe->handle($exception->currentId),
            ]);
        } catch (TodoRecordNotFoundException|TodoRecordUnavailableException|TodoValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured(['conflict' => false] + $describe->handle($issue->id));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('The local Todo issue id to revise.'),
            'expectedRevision' => $schema->integer()->required()->description('The `revision` value last read for this issue.'),
            'title' => $schema->string()->nullable()->description('The new title, if changing it.'),
            'body' => $schema->string()->nullable()->description('The new canonical body, if changing it.'),
            'groupId' => $schema->integer()->nullable()->description('A Group id within the issue\'s existing area, if moving it to a different Group. The issue must already belong to an area/Project.'),
            'newGroupName' => $schema->string()->nullable()->description('Move the issue to the Group with this name in its area, creating it if missing (case-insensitive). The issue must already belong to an area. Use at most one of groupId, newGroupName, clearGroup.'),
            'clearGroup' => $schema->boolean()->description('Remove the issue from its Group.'),
            'parentId' => $schema->integer()->nullable()->description('Move the issue under this local issue id (cycle-checked). Not combinable with clearParent.'),
            'clearParent' => $schema->boolean()->description('Detach the issue from its parent.'),
            'addLabels' => $schema->array()->items($schema->string())->description('Label names to attach, e.g. ["bug", "agent task"]: matched case-insensitively against existing labels and created if missing, stored lowercase. Knowledge labels (research, lesson, decision, guide) work too and move the issue to Knowledge.'),
            'removeLabels' => $schema->array()->items($schema->string())->description('Label names to detach; a label the issue does not carry is ignored. A name cannot be in both lists.'),
            'note' => $schema->string()->nullable()->description('An optional concise note explaining the revision, added as a comment.'),
            'idempotencyKey' => $schema->string()->nullable()->description('A caller-chosen key; retrying the same key returns the original result instead of revising again.'),
        ];
    }
}
