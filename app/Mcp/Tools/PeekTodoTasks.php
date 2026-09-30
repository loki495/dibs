<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\PeekTodoIssues as PeekTodoIssuesAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Cheaply checks whether one or more issues have changed since you last read them, without paying for their full body: returns id, title, revision and state for each. Use this instead of calling todo_show again on an id just to check whether your cached copy is still current. revision only bumps on todo_revise (title/body/Group) — it says nothing about state, labels, comments, claims or Priority, so compare state too if closing/reopening matters, and re-fetch in full if you need to know about those other kinds of change. An id with no matching issue is listed under unresolved rather than failing the call; a deleted issue is still returned, with available: false.')]
class PeekTodoTasks extends Tool implements Errable
{
    protected string $name = 'todo_peek';

    public function handle(Request $request, PeekTodoIssuesAction $peek): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.PeekTodoIssuesAction::MAX_IDS],
            'ids.*' => ['integer', 'min:1'],
        ]);

        return Response::structured($peek->handle($arguments['ids']));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ids' => $schema->array()->items($schema->integer()->min(1))
                ->min(1)->max(PeekTodoIssuesAction::MAX_IDS)->required()
                ->description('Local Todo issue ids to check — up to '.PeekTodoIssuesAction::MAX_IDS.' per call.'),
        ];
    }
}
