<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\ListTodoIssues;
use App\Actions\SearchTodoIssues as SearchTodoIssuesAction;
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
    protected string $name = 'todo_search';

    public function handle(Request $request, SearchTodoIssuesAction $search): ResponseFactory
    {
        $arguments = $request->validate([
            'query' => ['required', 'string', 'max:'.SearchTodoIssuesAction::MAX_QUERY_LENGTH, 'regex:/\S/u'],
            'area' => ['sometimes', 'integer', 'min:1'],
            'group' => ['sometimes', 'integer', 'min:1'],
            'label' => ['sometimes', 'string'],
            'state' => ['sometimes', 'string', 'in:OPEN,CLOSED,ALL'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:'.ListTodoIssues::MAX_PER_PAGE],
        ], attributes: ['perPage' => 'perPage']);

        return Response::structured($search->handle(
            query: $arguments['query'],
            area: $arguments['area'] ?? null,
            group: $arguments['group'] ?? null,
            label: $arguments['label'] ?? null,
            state: $arguments['state'] ?? 'ALL',
            page: $arguments['page'] ?? 1,
            perPage: $arguments['perPage'] ?? ListTodoIssues::DEFAULT_PER_PAGE,
        ));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->max(SearchTodoIssuesAction::MAX_QUERY_LENGTH)->description('Required keywords; all terms must match title or body. Literal substrings, ASCII case-insensitive; no special query syntax.')->required(),
            'area' => $schema->integer()->min(1)->description('A GitHub Project id to scope results to.'),
            'group' => $schema->integer()->min(1)->description('A Group option id to scope results to.'),
            'label' => $schema->string()->description('An exact label name every result must carry.'),
            'state' => $schema->string()->enum(['OPEN', 'CLOSED', 'ALL'])->default('ALL'),
            'page' => $schema->integer()->min(1)->default(1),
            'perPage' => $schema->integer()->min(1)->max(ListTodoIssues::MAX_PER_PAGE)->default(ListTodoIssues::DEFAULT_PER_PAGE),
        ];
    }
}
