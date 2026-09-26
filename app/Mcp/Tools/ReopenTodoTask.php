<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoIssue;
use App\Actions\ReopenTodoIssue;
use App\Exceptions\TodoRecordUnavailableException;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Reopens a closed task: sets it back to OPEN, clears state_reason (the same as GitHub itself does on a real reopen — a reason only ever describes how something closed), and enqueues the GitHub reopen. Not claim-scoped, unlike todo_complete — anyone can reopen any closed task, the same as the workspace UI\'s own Reopen icon. Earlier closing notes stay in the comment history; call todo_show afterward to confirm closing is now null.')]
class ReopenTodoTask extends Tool implements Errable
{
    protected string $name = 'todo_reopen';

    public function handle(Request $request, ReopenTodoIssue $reopen, DescribeTodoIssue $describe): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'issueId' => ['required', 'integer', 'min:1'],
        ]);

        $issue = Issue::query()->find($arguments['issueId']);
        if (! $issue instanceof Issue) {
            return Response::error("Issue {$arguments['issueId']} was not found.");
        }

        try {
            $issue = $reopen->handle($issue);
        } catch (TodoRecordUnavailableException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured($describe->handle($issue->id));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issueId' => $schema->integer()->required()->description('The local issue id to reopen.'),
        ];
    }
}
