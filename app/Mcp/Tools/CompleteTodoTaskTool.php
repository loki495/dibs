<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CloseTodoIssue;
use App\Actions\CompleteTodoTask;
use App\Actions\DescribeTodoIssue;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoValidationException;
use App\Mcp\CallerPid;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Finishes the calling agent\'s own claimed task: closes the issue, enqueues the GitHub push, and releases the claim. Only the exact process holding the live claim (matched by capability token) can complete it. `summary` becomes the closing note (a comment on the issue); `reason` and `references` are optional extra detail on why and how it was closed. `result` is the structured evidence (commit, tests, docs, done_when_held); the completion guards refuse a close that lacks what the task\'s guard profile asks for (a summary always; a commit for agent tasks, bugs and plan steps; tests and docs whenever a commit SHA is given; Done-when for plan steps), and the refusal lists each missing field. Where evidence truly does not apply, give "none: <the real reason>" for that field, or force: true with a real forceReason (logged and noted in the closing note). Call todo_show afterward for the `closing` object.')]
class CompleteTodoTaskTool extends Tool implements Errable
{
    protected string $name = 'todo_complete';

    public function handle(Request $request, CompleteTodoTask $complete, DescribeTodoIssue $describe, CallerPid $callerPid): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'issueId' => ['required', 'integer', 'min:1'],
            'capabilityToken' => ['required', 'string'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'reason' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', CloseTodoIssue::REASONS)],
            'references' => ['sometimes', 'array', 'max:20'],
            'references.*' => ['string', 'max:500'],
            'result' => ['sometimes', 'array'],
            'result.commit' => ['sometimes', 'string', 'max:2000'],
            'result.tests' => ['sometimes'],
            'result.docs' => ['sometimes'],
            'result.done_when_held' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
            'forceReason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        try {
            $issue = $complete->handle(
                issueId: $arguments['issueId'],
                pid: $callerPid->resolve(),
                capabilityToken: $arguments['capabilityToken'],
                summary: $arguments['summary'] ?? null,
                reason: $arguments['reason'] ?? null,
                references: array_values(array_map(strval(...), $arguments['references'] ?? [])),
                result: $arguments['result'] ?? [],
                force: $arguments['force'] ?? false,
                forceReason: $arguments['forceReason'] ?? null,
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
            'result' => $schema->object([
                'commit' => $schema->string()->description('The commit SHA (7-40 hex), or "none: <why no commit>".'),
                'tests' => $schema->object(['command' => $schema->string()->required(), 'exit_code' => $schema->integer()->required()])->description('The test run: {command, exit_code}; exit_code must be 0. Or the string "none: <why no tests>".'),
                'docs' => $schema->array()->items($schema->string())->description('Doc paths updated, or the string "none: <why no doc changed>".'),
                'done_when_held' => $schema->boolean()->description('For a plan step: true once the step\'s Done-when holds.'),
            ])->description('Structured completion evidence, kept with the closing note and shown in todo_show `closing.result`.'),
            'force' => $schema->boolean()->description('Complete despite missing evidence. Requires forceReason.'),
            'forceReason' => $schema->string()->nullable()->description('Why the missing evidence does not apply, in at least 5 real words (filler such as "force" or "just close" is refused). Logged in the activity log and added to the closing note.'),
            'references' => $schema->array()->items($schema->string()->max(500))->max(20)->description('Optional commit/PR references or other pointers, shown alongside the closing note. Ignored without `summary`.'),
        ];
    }
}
