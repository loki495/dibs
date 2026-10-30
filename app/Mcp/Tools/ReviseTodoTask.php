<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoIssue;
use App\Actions\ReviseTodoIssue;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Mcp\Tools\Concerns\ReplaceArgument;
use App\Mcp\Tools\Concerns\StepArgument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Revises an issue\'s canonical title, body, Area (areaId, areaName or clearArea), Priority (priorityId, priorityName or clearPriority), Group (groupId, newGroupName or clearGroup), parent (parentId or clearParent), labels (addLabels/removeLabels deltas by name), explicit links (addLinks/removeLinks) and/or plan step fields (step, blockedBy) — optimistic concurrency against local SQLite, not GitHub: pass the `revision` you last read from todo_show/todo_list as expectedRevision. If the issue changed since then, this returns {conflict: true, current: <fresh todo_show detail>} instead of applying anything or erroring, so you can reread and reconcile. groupId moves the issue to a different Group within its area (the issue must belong to an area, or be given one with areaId/areaName in the same call; moving Area drops the old Group and Priority); use clearGroup to remove it. Add an optional concise note to record why, without duplicating the whole body into a comment. This is for the canonical document itself — use todo_comment for supporting discussion/evidence.')]
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
            'replace' => ['sometimes', 'array', 'min:1', 'max:50'],
            'replace.*.from' => ['required', 'string', 'max:65535'],
            'replace.*.to' => ['present', 'nullable', 'string', 'max:65535'],
            'replace.*.all' => ['sometimes', 'boolean'],
            'groupId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'newGroupName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'clearGroup' => ['sometimes', 'boolean'],
            'areaId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'areaName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'clearArea' => ['sometimes', 'boolean'],
            'priorityId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'priorityName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'clearPriority' => ['sometimes', 'boolean'],
            'parentId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'clearParent' => ['sometimes', 'boolean'],
            'addLabels' => ['sometimes', 'array', 'max:20'],
            'addLabels.*' => ['string', 'max:100'],
            'removeLabels' => ['sometimes', 'array', 'max:20'],
            'removeLabels.*' => ['string', 'max:100'],
            'addLinks' => ['sometimes', 'array', 'max:50'],
            'addLinks.*.issueId' => ['required', 'integer', 'min:1'],
            'addLinks.*.commentId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'removeLinks' => ['sometimes', 'array', 'max:50'],
            'removeLinks.*.issueId' => ['required', 'integer', 'min:1'],
            'removeLinks.*.commentId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'idempotencyKey' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...StepArgument::rules(),
        ]);
        $stepArguments = StepArgument::normalize($arguments);

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
                addLinks: $arguments['addLinks'] ?? [],
                removeLinks: $arguments['removeLinks'] ?? [],
                replace: ReplaceArgument::normalize($arguments['replace'] ?? []),
                step: $stepArguments['step'],
                blockedBy: $stepArguments['blockedBy'],
                areaId: $arguments['areaId'] ?? null,
                areaName: $arguments['areaName'] ?? null,
                clearArea: $arguments['clearArea'] ?? false,
                priorityId: $arguments['priorityId'] ?? null,
                priorityName: $arguments['priorityName'] ?? null,
                clearPriority: $arguments['clearPriority'] ?? false,
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

        if (isset($arguments['replace'])) {
            return Response::structured(['conflict' => false, 'id' => $issue->id, 'revision' => $issue->revision]);
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
            'replace' => ReplaceArgument::schema($schema, 'body'),
            'groupId' => $schema->integer()->nullable()->description('A Group id within the issue\'s existing area, if moving it to a different Group. The issue must already belong to an area/Project.'),
            'newGroupName' => $schema->string()->nullable()->description('Move the issue to the Group with this name in its area, creating it if missing (case-insensitive). The issue must already belong to an area. Use at most one of groupId, newGroupName, clearGroup.'),
            'clearGroup' => $schema->boolean()->description('Remove the issue from its Group.'),
            'areaId' => $schema->integer()->nullable()->description('Move the issue to this Area (Project id from todo_metadata). The new membership starts with no Group or Priority (the old Area\'s options do not carry over) unless groupId/newGroupName/priorityId/priorityName in the same call set them from the new Area. Use at most one of areaId, areaName, clearArea.'),
            'areaName' => $schema->string()->nullable()->description('Move the issue to the Area with this title (case-insensitive; must exist).'),
            'clearArea' => $schema->boolean()->description('Remove the issue from its Area (and so its Group and Priority). Not combinable with Group or Priority changes.'),
            'priorityId' => $schema->integer()->nullable()->description('A Priority option id of the issue\'s (new) Area. The issue must already belong to an area, or be given one in the same call. Use at most one of priorityId, priorityName, clearPriority.'),
            'priorityName' => $schema->string()->nullable()->description('The Priority option of the issue\'s (new) Area with this name (case-insensitive; must exist).'),
            'clearPriority' => $schema->boolean()->description('Remove the issue\'s Priority.'),
            'parentId' => $schema->integer()->nullable()->description('Move the issue under this local issue id (cycle-checked). Not combinable with clearParent.'),
            'clearParent' => $schema->boolean()->description('Detach the issue from its parent.'),
            'addLabels' => $schema->array()->items($schema->string())->description('Label names to attach, e.g. ["bug", "agent task"]: matched case-insensitively against existing labels and created if missing, stored lowercase. Knowledge labels (research, lesson, decision, guide) work too and move the issue to Knowledge.'),
            'removeLabels' => $schema->array()->items($schema->string())->description('Label names to detach; a label the issue does not carry is ignored. A name cannot be in both lists.'),
            'addLinks' => $schema->array()->items($schema->object(['issueId' => $schema->integer()->required(), 'commentId' => $schema->integer()->nullable()]))->description('Explicit links to records that apply to this one: each {issueId} links an issue (a rule, lesson, research, decision, guide or task; its kind is read from its labels), {issueId, commentId} links one comment of it. Duplicates are ignored; a missing or self target is an error.'),
            'removeLinks' => $schema->array()->items($schema->object(['issueId' => $schema->integer()->required(), 'commentId' => $schema->integer()->nullable()]))->description('Links to remove, same shape as addLinks; removing a link that does not exist is ignored.'),
            'note' => $schema->string()->nullable()->description('An optional concise note explaining the revision, added as a comment.'),
            'idempotencyKey' => $schema->string()->nullable()->description('A caller-chosen key; retrying the same key returns the original result instead of revising again.'),
            ...StepArgument::schema($schema),
        ];
    }
}
