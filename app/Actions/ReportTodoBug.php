<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;

/**
 * Lets an agent self-report a problem with the MCP/CLI tooling itself — distinct from
 * CreateTodoIssue's use for real product tasks. Always applies the dedicated `agent-report`
 * label (via CreateTodoIssue's existing case-insensitive create-or-reuse-by-name mechanism)
 * so these are filterable without polluting the `bug` label used for real product defects.
 */
class ReportTodoBug
{
    private const string LABEL = 'agent-report';

    public function __construct(private readonly CreateTodoIssue $create) {}

    public function handle(
        string $summary,
        string $details,
        ?string $toolOrCommand = null,
        ?string $arguments = null,
        ?string $idempotencyKey = null,
    ): Issue {
        return $this->create->handle(
            title: trim($summary),
            body: $this->composeBody(trim($details), $toolOrCommand, $arguments),
            newLabelNames: [self::LABEL],
            idempotencyKey: $idempotencyKey,
        );
    }

    private function composeBody(string $details, ?string $toolOrCommand, ?string $arguments): string
    {
        $header = [];
        if ($toolOrCommand !== null && trim($toolOrCommand) !== '') {
            $header[] = '**Tool/command:** '.trim($toolOrCommand);
        }
        if ($arguments !== null && trim($arguments) !== '') {
            $header[] = '**Arguments:** '.trim($arguments);
        }

        return $header === [] ? $details : implode("\n", $header)."\n\n".$details;
    }
}
