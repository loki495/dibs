<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\ListTodoIssues;
use App\Mcp\Tools\Concerns\AcceptsIssueFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Lists Todo issues in a bounded, paginated page. Filter by area (a GitHub Project id), group (a Group option id), label, or a free-text search; choose the "tasks" view (default, excludes knowledge records) or "knowledge" view (research/lesson/decision/guide only). Pass parentId to list only the direct children of one issue. Each item is a bounded summary — call todo_show for full detail.')]
class ListTodoTasks extends Tool implements Errable
{
    use AcceptsIssueFilters;

    protected string $name = 'todo_list';

    public function handle(Request $request, ListTodoIssues $list): ResponseFactory
    {
        $arguments = $request->validate([
            'area' => ['sometimes', 'integer', 'min:1'],
            'group' => ['sometimes', 'integer', 'min:1'],
            'label' => ['sometimes', 'string'],
            'view' => ['sometimes', 'string', 'in:tasks,knowledge'],
            'state' => ['sometimes', 'string', 'in:OPEN,CLOSED,ALL'],
            'search' => ['sometimes', 'string'],
            'parentId' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:'.ListTodoIssues::MAX_PER_PAGE],
            ...$this->filterRules(),
        ], attributes: $this->filterAttributes());

        $result = $list->handle(
            area: $arguments['area'] ?? null,
            group: $arguments['group'] ?? null,
            label: $arguments['label'] ?? null,
            view: $arguments['view'] ?? 'tasks',
            state: $arguments['state'] ?? 'OPEN',
            search: $arguments['search'] ?? '',
            page: $arguments['page'] ?? 1,
            perPage: $arguments['perPage'] ?? ListTodoIssues::DEFAULT_PER_PAGE,
            filters: $this->issueFiltersFrom($arguments),
        );

        return Response::structured($result);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'area' => $schema->integer()->description('A GitHub Project id to scope results to.'),
            'group' => $schema->integer()->description('A Group option id to scope results to.'),
            'label' => $schema->string()->description('A label name (case-insensitive) every result must carry; `labels` takes several.'),
            'view' => $schema->string()->enum(['tasks', 'knowledge'])->default('tasks')->description('"tasks" excludes research/lesson/decision/guide records; "knowledge" includes only them.'),
            'state' => $schema->string()->enum(['OPEN', 'CLOSED', 'ALL'])->default('OPEN')->description('Issue state filter.'),
            'search' => $schema->string()->description('Free-text match against title or issue number.'),
            'parentId' => $schema->integer()->description('List only the direct children of this local issue id, or with `descendants` the whole tree beneath it.'),
            ...$this->filterSchema($schema),
            'page' => $schema->integer()->min(1)->default(1),
            'perPage' => $schema->integer()->min(1)->max(ListTodoIssues::MAX_PER_PAGE)->default(ListTodoIssues::DEFAULT_PER_PAGE),
        ];
    }
}
