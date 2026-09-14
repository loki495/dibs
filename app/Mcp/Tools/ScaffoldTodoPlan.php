<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoIssue;
use App\Actions\ScaffoldTodoPlan as ScaffoldTodoPlanAction;
use App\Exceptions\TodoValidationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Creates a plan issue together with its initial child task issues in one atomic call: if any child fails validation, nothing is created at all — never a plan with a partial set of children. Each child is scoped to the same area as the plan. groupId/priorityId set the plan issue\'s own Group/Priority; each child accepts its own groupId/priorityId independently. Apply the "plan" label via labelIds if the plan should read as a maintained plan document. Pass idempotencyKey to make a retried call return the original plan instead of duplicating it.')]
class ScaffoldTodoPlan extends Tool implements Errable
{
    protected string $name = 'todo_scaffold_plan';

    public function handle(Request $request, ScaffoldTodoPlanAction $scaffold, DescribeTodoIssue $describe): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'area' => ['sometimes', 'integer', 'min:1'],
            'groupId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'priorityId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'labelIds' => ['sometimes', 'array'],
            'labelIds.*' => ['integer'],
            'newLabelName' => ['sometimes', 'nullable', 'string', 'max:50'],
            'children' => ['sometimes', 'array'],
            'children.*.title' => ['required', 'string', 'max:255'],
            'children.*.body' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'children.*.groupId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'children.*.priorityId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'children.*.labelIds' => ['sometimes', 'array'],
            'children.*.labelIds.*' => ['integer'],
            'idempotencyKey' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $plan = $scaffold->handle(
                title: $arguments['title'],
                body: $arguments['body'] ?? null,
                area: $arguments['area'] ?? null,
                groupId: $arguments['groupId'] ?? null,
                priorityId: $arguments['priorityId'] ?? null,
                labelIds: $arguments['labelIds'] ?? [],
                newLabelName: $arguments['newLabelName'] ?? null,
                children: $arguments['children'] ?? [],
                idempotencyKey: $arguments['idempotencyKey'] ?? null,
            );
        } catch (TodoValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured($describe->handle($plan->id));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('The plan issue title.'),
            'body' => $schema->string()->nullable()->description('The plan\'s maintained objective/status/decisions document.'),
            'area' => $schema->integer()->description('A GitHub Project id; every child inherits it too.'),
            'groupId' => $schema->integer()->nullable()->description('A Group id (within `area`) for the plan issue itself — independent of each child\'s own `groupId`.'),
            'priorityId' => $schema->integer()->nullable()->description('A Priority id (within `area`) for the plan issue itself — independent of each child\'s own `priorityId`.'),
            'labelIds' => $schema->array()->items($schema->integer())->description('Existing label ids for the plan issue (e.g. a "plan" label).'),
            'newLabelName' => $schema->string()->nullable()->description('Create (or reuse, case-insensitively) a label by name for the plan issue.'),
            'children' => $schema->array()->items($schema->object([
                'title' => $schema->string()->required(),
                'body' => $schema->string()->nullable(),
                'groupId' => $schema->integer()->nullable(),
                'priorityId' => $schema->integer()->nullable(),
                'labelIds' => $schema->array()->items($schema->integer()),
            ]))->description('Initial child task issues, each nested under the new plan.'),
            'idempotencyKey' => $schema->string()->nullable()->description('A caller-chosen key; retrying the same key returns the original plan instead of duplicating it.'),
        ];
    }
}
