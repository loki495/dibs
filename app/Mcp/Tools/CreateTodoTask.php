<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CreateTodoIssue;
use App\Actions\CreateTodoIssues;
use App\Actions\DescribeTodoIssue;
use App\Exceptions\TodoValidationException;
use App\Mcp\Tools\Concerns\StepArgument;
use App\Support\TodoCreationInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Creates one Todo task, or a best-effort batch via tasks (1–20 independent definitions, each with its own optional idempotencyKey; cannot combine with single-task fields). Batch results preserve order with compact issue summaries or per-item errors and succeeded/failed counts. Parent IDs for new writes must exist before the batch; use scaffold_plan for a new plan/tree. Single creation returns full detail. Creates a task (or plan, or knowledge record — apply a research/lesson/decision/guide label via labelNames or labelIds) locally and enqueues its GitHub push. Set area (a GitHub Project id) and optionally groupId/priorityId (must belong to that area) or newGroupName to create a Group by name. Set parentId to nest under an existing issue. An omitted or null area inherits the parent\'s sole active project (several projects require an explicit area); an omitted or null groupId inherits its Group in the effective area unless newGroupName is set. Explicit project/Group choices win, Groups never cross projects, and Priority is not inherited. Pass idempotencyKey to make a retried call return the original result instead of creating a duplicate. Call todo_show with the returned id for full detail.')]
class CreateTodoTask extends Tool implements Errable
{
    protected string $name = 'todo_create';

    public function handle(Request $request, CreateTodoIssue $create, DescribeTodoIssue $describe, CreateTodoIssues $batch): Response|ResponseFactory
    {
        if (array_key_exists('tasks', $request->all())) {
            $arguments = $request->validate(['tasks' => ['required', 'array', 'list', 'min:1', 'max:'.CreateTodoIssues::MAX_TASKS]]);
            if (count($request->all()) !== 1) {
                return Response::error('Provide either tasks or single-task fields, not both.');
            }

            return Response::structured($batch->handle($arguments['tasks']));
        }
        $arguments = $request->validate(TodoCreationInput::rules());

        try {
            $issue = $create->handle(...TodoCreationInput::arguments($arguments));
        } catch (TodoValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured($describe->handle($issue->id));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        $single = $this->taskSchema($schema);
        $single['title']->required(false);

        return [...$single, 'tasks' => $schema->array()->items($schema->object($this->taskSchema($schema)))
            ->min(1)->max(CreateTodoIssues::MAX_TASKS)->description('Create 1–20 independent tasks, best effort, in order. Cannot combine with single-task fields. Each item accepts the normal create fields and its own optional idempotencyKey; existing parent IDs only. Returns compact per-task issue/error results, succeeded and failed counts.')];
    }

    /** @return array<string, Type> */
    private function taskSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('The task title.'),
            'body' => $schema->string()->nullable()->description('The canonical body/description.'),
            'area' => $schema->integer()->nullable()->description('A GitHub Project id. Omitted or null inherits the parent\'s sole active project; a parent in several projects requires an explicit area.'),
            'parentId' => $schema->integer()->description('Nest this issue under an existing local issue id.'),
            'groupId' => $schema->integer()->nullable()->description('A Group option id in the effective area. Omitted or null inherits the parent\'s Group in that area unless newGroupName is given. Explicit values override the default.'),
            'priorityId' => $schema->integer()->description('A Priority option id in the explicit or inherited area. Priority is never inherited.'),
            'labelIds' => $schema->array()->items($schema->integer())->description('Existing label ids to attach.'),
            'newGroupName' => $schema->string()->nullable()->description('Create (or reuse, case-insensitively) a Group by name in the explicit or inherited area, overriding the parent Group. Requires an effective area.'),
            'newLabelName' => $schema->string()->nullable()->description('Create (or reuse, case-insensitively) a label by name.'),
            'labelNames' => $schema->array()->items($schema->string())->description('Label names to attach, e.g. ["bug", "agent task"]. Each is matched case-insensitively against existing labels and created if missing.'),
            'addLinks' => $schema->array()->items($schema->object(['issueId' => $schema->integer()->required(), 'commentId' => $schema->integer()->nullable()]))->description('Explicit links to records that apply to this one: each {issueId} links an issue (a rule, lesson, research, decision, guide or task; its kind is read from its labels), {issueId, commentId} links one comment of it. Duplicates are ignored; a missing or self target is an error.'),
            ...StepArgument::schema($schema, 'Local ids of sibling steps (children of the same parentId) this step waits on; it is not ready until they all close. Self, cross-plan and cyclic edges are refused.'),
            'idempotencyKey' => $schema->string()->nullable()->description('A caller-chosen key; retrying the same key returns the original result instead of creating a duplicate.'),
        ];
    }
}
