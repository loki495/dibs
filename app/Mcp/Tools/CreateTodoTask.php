<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CreateTodoIssue;
use App\Actions\DescribeTodoIssue;
use App\Exceptions\TodoValidationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Creates a new Todo task (or plan, or knowledge record — apply a research/lesson/decision/guide label via labelIds) locally and enqueues its GitHub push. Set area (a GitHub Project id) and optionally groupId/priorityId (must belong to that area) or newGroupName to create a Group by name. Set parentId to nest under an existing issue. Pass idempotencyKey to make a retried call return the original result instead of creating a duplicate. Call todo_show with the returned id for full detail.')]
class CreateTodoTask extends Tool implements Errable
{
    protected string $name = 'todo_create';

    public function handle(Request $request, CreateTodoIssue $create, DescribeTodoIssue $describe): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'area' => ['sometimes', 'integer', 'min:1'],
            'parentId' => ['sometimes', 'integer', 'min:1'],
            'groupId' => ['sometimes', 'integer', 'min:1'],
            'priorityId' => ['sometimes', 'integer', 'min:1'],
            'labelIds' => ['sometimes', 'array'],
            'labelIds.*' => ['integer'],
            'newGroupName' => ['sometimes', 'nullable', 'string', 'max:50'],
            'newLabelName' => ['sometimes', 'nullable', 'string', 'max:50'],
            'idempotencyKey' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $issue = $create->handle(
                title: $arguments['title'],
                body: $arguments['body'] ?? null,
                area: $arguments['area'] ?? null,
                parentId: $arguments['parentId'] ?? null,
                groupId: $arguments['groupId'] ?? null,
                priorityId: $arguments['priorityId'] ?? null,
                labelIds: $arguments['labelIds'] ?? [],
                newGroupName: $arguments['newGroupName'] ?? null,
                newLabelNames: isset($arguments['newLabelName']) ? [$arguments['newLabelName']] : [],
                idempotencyKey: $arguments['idempotencyKey'] ?? null,
            );
        } catch (TodoValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured($describe->handle($issue->id));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('The task title.'),
            'body' => $schema->string()->nullable()->description('The canonical body/description.'),
            'area' => $schema->integer()->description('A GitHub Project id.'),
            'parentId' => $schema->integer()->description('Nest this issue under an existing local issue id.'),
            'groupId' => $schema->integer()->description('A Group option id; must belong to `area`.'),
            'priorityId' => $schema->integer()->description('A Priority option id; must belong to `area`.'),
            'labelIds' => $schema->array()->items($schema->integer())->description('Existing label ids to attach.'),
            'newGroupName' => $schema->string()->nullable()->description('Create (or reuse, case-insensitively) a Group by name within `area`. Requires `area`.'),
            'newLabelName' => $schema->string()->nullable()->description('Create (or reuse, case-insensitively) a label by name.'),
            'idempotencyKey' => $schema->string()->nullable()->description('A caller-chosen key; retrying the same key returns the original result instead of creating a duplicate.'),
        ];
    }
}
