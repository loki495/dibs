<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoIssue;
use App\Actions\ReportTodoBug;
use App\Exceptions\TodoValidationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Reports a problem with the Todo MCP/CLI tooling itself — a bug, confusing behavior, or unexpected error hit while using these tools. NOT for product tasks; use todo_create for those. Creates a GitHub issue labeled agent report and returns its full detail; add more detail later with todo_comment against the returned id.')]
class ReportBugTool extends Tool implements Errable
{
    protected string $name = 'todo_report_bug';

    public function handle(Request $request, ReportTodoBug $report, DescribeTodoIssue $describe): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'summary' => ['required', 'string', 'max:255'],
            'details' => ['required', 'string', 'max:65535'],
            'toolOrCommand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'arguments' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'idempotencyKey' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $issue = $report->handle(
                summary: $arguments['summary'],
                details: $arguments['details'],
                toolOrCommand: $arguments['toolOrCommand'] ?? null,
                arguments: $arguments['arguments'] ?? null,
                idempotencyKey: $arguments['idempotencyKey'] ?? null,
            );
        } catch (TodoValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured($describe->handle($issue->id));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required()->description('A short title describing the problem.'),
            'details' => $schema->string()->required()->description('Freeform details: repro steps, the error received, what was expected, and anything else you would naturally put in a bug report.'),
            'toolOrCommand' => $schema->string()->nullable()->description('The specific tool or CLI command in use when the problem occurred, e.g. "todo_revise".'),
            'arguments' => $schema->string()->nullable()->description('The exact arguments/parameters passed to that call, as a string (e.g. JSON).'),
            'idempotencyKey' => $schema->string()->nullable()->description('A caller-chosen key; retrying the same key returns the original report instead of duplicating it.'),
        ];
    }
}
