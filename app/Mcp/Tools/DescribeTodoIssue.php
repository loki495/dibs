<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoIssue as DescribeTodoIssueAction;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Retrieves full detail for one Todo issue by its local id: raw body, labels, parent and direct-children summaries, and Project memberships (area/group/priority/status/planned/due). This also serves as the plan bundle for a plan-labeled issue — its body is the maintained objective/status/decisions/acceptance-criteria document, and its children are the plan\'s task summaries. Comments are not included unless withComments is true, and are paginated when they are.')]
class DescribeTodoIssue extends Tool implements Errable
{
    protected string $name = 'todo_show';

    public function handle(Request $request, DescribeTodoIssueAction $describe): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'withComments' => ['sometimes', 'boolean'],
            'commentsPage' => ['sometimes', 'integer', 'min:1'],
            'commentsPerPage' => ['sometimes', 'integer', 'min:1', 'max:'.DescribeTodoIssueAction::MAX_COMMENTS_PER_PAGE],
        ]);

        try {
            $result = $describe->handle(
                id: $arguments['id'],
                withComments: $arguments['withComments'] ?? false,
                commentsPage: $arguments['commentsPage'] ?? 1,
                commentsPerPage: $arguments['commentsPerPage'] ?? DescribeTodoIssueAction::DEFAULT_COMMENTS_PER_PAGE,
            );
        } catch (TodoRecordNotFoundException|TodoRecordUnavailableException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured($result);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The local Todo issue id (not the GitHub issue number).')->required(),
            'withComments' => $schema->boolean()->default(false)->description('Include a paginated page of comments.'),
            'commentsPage' => $schema->integer()->min(1)->default(1),
            'commentsPerPage' => $schema->integer()->min(1)->max(DescribeTodoIssueAction::MAX_COMMENTS_PER_PAGE)->default(DescribeTodoIssueAction::DEFAULT_COMMENTS_PER_PAGE),
        ];
    }
}
