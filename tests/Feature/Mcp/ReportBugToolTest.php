<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ReportBugTool;
use App\Models\GitHubRepository;
use App\Models\Issue;

beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

it('exposes the tool under the todo_report_bug name', function (): void {
    expect(app(ReportBugTool::class)->name())->toBe('todo_report_bug');
});

it('files a bug report and returns full issue detail', function (): void {
    TodoServer::tool(ReportBugTool::class, [
        'summary' => 'todo_revise conflict message is confusing',
        'details' => 'Reread and reconciled but the message did not mention the field that changed.',
        'toolOrCommand' => 'todo_revise',
        'arguments' => '{"id":42}',
    ])->assertOk()->assertHasNoErrors()->assertSee('todo_revise conflict message is confusing')->assertSee('agent-report');

    $issue = Issue::query()->where('title', 'todo_revise conflict message is confusing')->sole();
    expect($issue->labels->pluck('name')->all())->toBe(['agent-report'])
        ->and($issue->body)->toContain('**Tool/command:** todo_revise');
});

it('files a report with only the required fields', function (): void {
    TodoServer::tool(ReportBugTool::class, ['summary' => 'Something odd', 'details' => 'Not sure which tool.'])
        ->assertOk()->assertHasNoErrors();

    expect(Issue::query()->where('title', 'Something odd')->exists())->toBeTrue();
});

it('returns a structured error when the repository is not configured', function (): void {
    GitHubRepository::query()->delete();

    TodoServer::tool(ReportBugTool::class, ['summary' => 'x', 'details' => 'y'])->assertHasErrors();

    expect(Issue::query()->count())->toBe(0);
});

it('rejects a call missing required arguments', function (): void {
    TodoServer::tool(ReportBugTool::class, [])->assertHasErrors();
});

it('is idempotent through the tool: retrying the same key does not duplicate the report', function (): void {
    TodoServer::tool(ReportBugTool::class, ['summary' => 'Dup', 'details' => 'x', 'idempotencyKey' => 'report-1'])->assertOk();
    TodoServer::tool(ReportBugTool::class, ['summary' => 'Dup', 'details' => 'x', 'idempotencyKey' => 'report-1'])->assertOk();

    expect(Issue::query()->where('title', 'Dup')->count())->toBe(1);
});
