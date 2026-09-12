<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CompleteTodoTask;
use App\Actions\DescribeTodoIssue;
use App\Exceptions\TodoRecordUnavailableException;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Finishes the calling agent\'s own claimed task: closes the issue, enqueues the GitHub push, optionally posts a result summary as a comment, and releases the claim. Only the exact process holding the live claim (matched by capability token) can complete it.')]
class CompleteTodoTaskTool extends Tool implements Errable
{
    protected string $name = 'todo_complete';

    public function handle(Request $request, CompleteTodoTask $complete, DescribeTodoIssue $describe): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'issueId' => ['required', 'integer', 'min:1'],
            'capabilityToken' => ['required', 'string'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ]);

        try {
            $issue = $complete->handle(
                issueId: $arguments['issueId'],
                pid: posix_getppid(),
                capabilityToken: $arguments['capabilityToken'],
                summary: $arguments['summary'] ?? null,
            );
        } catch (DomainException|TodoRecordUnavailableException $exception) {
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
            'summary' => $schema->string()->nullable()->description('An optional result summary, posted as a comment.'),
        ];
    }
}
