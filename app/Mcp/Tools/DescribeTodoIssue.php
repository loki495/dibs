<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoIssue as DescribeTodoIssueAction;
use App\Actions\DescribeTodoIssues;
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

#[Description('Retrieves Todo issue details by local id: supply id for the original single record, or ids (1–20) for ordered items with issue or per-id error, preserving duplicates. Batch responses share a total JSON budget (maxResponseChars, default 60000); truncatedFields marks clipped text, omittedFields marks removed sections. Read one id for full detail. Both modes include: raw body, labels, parent and direct-children summaries, and Project memberships (area/group/priority/status/planned/due). This also serves as the plan bundle for a plan-labeled issue — its body is the maintained objective/status/decisions/acceptance-criteria document, and its children are the plan\'s task summaries. Comments are not included unless withComments is true, and are paginated when they are. Pass maxBodyLength to truncate a long body server-side (bodyTruncated in the response says whether it did) for a cheap "what is this about" scan before deciding to re-read it in full — todo_peek is cheaper still if you only need to know whether it changed at all.')]
class DescribeTodoIssue extends Tool implements Errable
{
    protected string $name = 'todo_show';

    public function handle(Request $request, DescribeTodoIssueAction $describe, DescribeTodoIssues $batch): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'id' => ['required_without:ids', 'prohibits:ids', 'integer', 'min:1'],
            'ids' => ['required_without:id', 'prohibits:id', 'array', 'list', 'min:1', 'max:'.DescribeTodoIssues::MAX_IDS],
            'ids.*' => ['required', 'integer', 'min:1'],
            'withComments' => ['sometimes', 'boolean'],
            'commentsPage' => ['sometimes', 'integer', 'min:1'],
            'commentsPerPage' => ['sometimes', 'integer', 'min:1', 'max:'.DescribeTodoIssueAction::MAX_COMMENTS_PER_PAGE],
            'maxBodyLength' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'maxResponseChars' => ['sometimes', 'prohibits:id', 'integer', 'min:'.DescribeTodoIssues::MIN_RESPONSE_CHARS, 'max:'.DescribeTodoIssues::MAX_RESPONSE_CHARS],
        ]);

        if (isset($arguments['ids'])) {
            return Response::structured($batch->handle(
                ids: array_map(intval(...), $arguments['ids']),
                withComments: $arguments['withComments'] ?? false,
                commentsPage: $arguments['commentsPage'] ?? 1,
                commentsPerPage: $arguments['commentsPerPage'] ?? DescribeTodoIssueAction::DEFAULT_COMMENTS_PER_PAGE,
                maxBodyLength: $arguments['maxBodyLength'] ?? null,
                maxResponseChars: $arguments['maxResponseChars'] ?? DescribeTodoIssues::DEFAULT_RESPONSE_CHARS,
            ));
        }

        try {
            $result = $describe->handle(
                id: $arguments['id'],
                withComments: $arguments['withComments'] ?? false,
                commentsPage: $arguments['commentsPage'] ?? 1,
                commentsPerPage: $arguments['commentsPerPage'] ?? DescribeTodoIssueAction::DEFAULT_COMMENTS_PER_PAGE,
                maxBodyLength: $arguments['maxBodyLength'] ?? null,
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
            'id' => $schema->integer()->min(1)->description('One local Todo issue id. Supply exactly one of id or ids; id keeps the original full-detail response.'),
            'ids' => $schema->array()->items($schema->integer()->min(1))->min(1)->max(DescribeTodoIssues::MAX_IDS)->description('Up to 20 local issue ids, in result order, including duplicates. Returns items with id and issue, or error. Missing/unavailable ids do not hide other results.'),
            'withComments' => $schema->boolean()->default(false)->description('Include a paginated page of comments.'),
            'commentsPage' => $schema->integer()->min(1)->default(1),
            'commentsPerPage' => $schema->integer()->min(1)->max(DescribeTodoIssueAction::MAX_COMMENTS_PER_PAGE)->default(DescribeTodoIssueAction::DEFAULT_COMMENTS_PER_PAGE),
            'maxBodyLength' => $schema->integer()->min(1)->description('Truncate the body to this many characters server-side; the response\'s bodyTruncated says whether it was cut.'),
            'maxResponseChars' => $schema->integer()->min(DescribeTodoIssues::MIN_RESPONSE_CHARS)->max(DescribeTodoIssues::MAX_RESPONSE_CHARS)->description('Batch only: total compact JSON character budget, default 60000 (20000–120000), shared equally between items. truncatedFields names fields with clipped text; omittedFields names sections omitted to fit. Read one id for full detail.'),
        ];
    }
}
