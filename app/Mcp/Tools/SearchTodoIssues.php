<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\ListTodoIssues;
use App\Actions\SearchTodoIssues as SearchTodoIssuesAction;
use App\Mcp\Tools\Concerns\AcceptsIssueFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Find related tasks, plans, and knowledge in one call by searching titles and bodies, including closed records by default. Every whitespace-separated term must match somewhere in the title or body; title matches rank first. Returns paginated summaries with matching body excerpts and parent IDs so you can judge relevance before calling todo_show. Does not search comments.')]
class SearchTodoIssues extends Tool implements Errable
{
    use AcceptsIssueFilters;

    protected string $name = 'todo_search';

    public function handle(Request $request, SearchTodoIssuesAction $search): ResponseFactory
    {
        $arguments = $request->validate([
            'query' => ['sometimes', 'nullable', 'string', 'max:'.SearchTodoIssuesAction::MAX_QUERY_LENGTH],
            'area' => ['sometimes', 'integer', 'min:1'],
            'group' => ['sometimes', 'integer', 'min:1'],
            'label' => ['sometimes', 'string'],
            'state' => ['sometimes', 'string', 'in:OPEN,CLOSED,ALL'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'parentId' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:'.ListTodoIssues::MAX_PER_PAGE],
            ...$this->filterRules(),
        ], attributes: ['perPage' => 'perPage', 'parentId' => 'parentId', ...$this->filterAttributes()]);

        return Response::structured($search->handle(
            query: (string) ($arguments['query'] ?? ''),
            area: $arguments['area'] ?? null,
            group: $arguments['group'] ?? null,
            label: $arguments['label'] ?? null,
            state: $arguments['state'] ?? 'ALL',
            page: $arguments['page'] ?? 1,
            perPage: $arguments['perPage'] ?? ListTodoIssues::DEFAULT_PER_PAGE,
            filters: $this->issueFiltersFrom($arguments),
        ));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->max(SearchTodoIssuesAction::MAX_QUERY_LENGTH)->description('Keywords; all terms must match title or body. Literal substrings, ASCII case-insensitive; no special query syntax. Optional when at least one filter is given, in which case results list in id order.'),
            'area' => $schema->integer()->min(1)->description('A GitHub Project id to scope results to.'),
            'group' => $schema->integer()->min(1)->description('A Group option id to scope results to.'),
            'label' => $schema->string()->description('A label name (case-insensitive) every result must carry; `labels` takes several.'),
            'parentId' => $schema->integer()->min(1)->description('Only issues under this local issue id: its direct children, or with `descendants` the whole tree beneath it.'),
            ...$this->filterSchema($schema),
            'state' => $schema->string()->enum(['OPEN', 'CLOSED', 'ALL'])->default('ALL'),
            'page' => $schema->integer()->min(1)->default(1),
            'perPage' => $schema->integer()->min(1)->max(ListTodoIssues::MAX_PER_PAGE)->default(ListTodoIssues::DEFAULT_PER_PAGE),
        ];
    }
}
