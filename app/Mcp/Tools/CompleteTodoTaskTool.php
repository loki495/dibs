<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CloseTodoIssue;
use App\Actions\CompleteTodoTask;
use App\Actions\DescribeTodoIssue;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoValidationException;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Finishes the calling agent\'s own claimed task: closes the issue, enqueues the GitHub push, and releases the claim. Only the exact process holding the live claim (matched by capability token) can complete it. `summary` becomes the closing note (a comment on the issue); `reason` and `references` are optional extra detail on why and how it was closed. Call todo_show afterward for the `closing` object.')]
class CompleteTodoTaskTool extends Tool implements Errable
{
    protected string $name = 'todo_complete';

    public function handle(Request $request, CompleteTodoTask $complete, DescribeTodoIssue $describe): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'issueId' => ['required', 'integer', 'min:1'],
            'capabilityToken' => ['required', 'string'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'reason' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', CloseTodoIssue::REASONS)],
            'references' => ['sometimes', 'array', 'max:20'],
            'references.*' => ['string', 'max:500'],
        ]);

        try {
            $issue = $complete->handle(
                issueId: $arguments['issueId'],
                pid: posix_getppid(),
                capabilityToken: $arguments['capabilityToken'],
                summary: $arguments['summary'] ?? null,
                reason: $arguments['reason'] ?? null,
                references: array_values(array_map(strval(...), $arguments['references'] ?? [])),
            );
        } catch (DomainException|TodoRecordUnavailableException|TodoValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured($describe->handle($issue->id));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issueId' => $schema->integer()->required()->description('The claimed issue\'s local id.'),
            'capabilityToken' => $schema->string()->required()->description('The token returned by todo_claim for this claim.'),
            'summary' => $schema->string()->nullable()->description('The closing note, posted as a comment. Omit for no note.'),
            'reason' => $schema->string()->enum(CloseTodoIssue::REASONS)->nullable()->description('Why the task was closed: COMPLETED or NOT_PLANNED. Pushed to GitHub as the issue\'s close reason.'),
            'references' => $schema->array()->items($schema->string()->max(500))->max(20)->description('Optional commit/PR references or other pointers, shown alongside the closing note. Ignored without `summary`.'),
        ];
    }
}
