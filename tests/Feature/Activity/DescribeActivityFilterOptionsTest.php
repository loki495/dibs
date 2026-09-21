<?php

declare(strict_types=1);

use App\Actions\DescribeActivityFilterOptions;
use App\Actions\ListActivityLog;
use App\Models\ChangeLog;
use App\Models\McpCallLog;

it('lists the distinct tools and statuses recorded in the MCP log, sorted', function (): void {
    McpCallLog::factory()->create(['tool' => 'todo_list', 'status' => 'ok']);
    McpCallLog::factory()->create(['tool' => 'todo_create', 'status' => 'error']);
    McpCallLog::factory()->create(['tool' => 'todo_list', 'status' => 'ok']);
    ChangeLog::factory()->create(['action' => 'NotAnMcpTool']);

    $options = app(DescribeActivityFilterOptions::class)->handle(ListActivityLog::MCP_CALLS);

    expect($options)->toBe(['types' => ['todo_create', 'todo_list'], 'statuses' => ['error', 'ok'], 'categories' => [], 'sources' => []]);
});

it('lists the distinct actions, categories and sources recorded in the change log, sorted', function (): void {
    ChangeLog::factory()->create(['action' => 'UpdateTodoIssue', 'category' => 'change', 'source' => 'ui']);
    ChangeLog::factory()->create(['action' => 'auth.login', 'category' => 'auth', 'source' => 'ui']);
    ChangeLog::factory()->create(['action' => 'UpdateTodoIssue', 'category' => 'change', 'source' => 'mcp']);
    McpCallLog::factory()->create(['tool' => 'not_a_change_action']);

    $options = app(DescribeActivityFilterOptions::class)->handle(ListActivityLog::CHANGES);

    expect($options)->toBe(['types' => ['UpdateTodoIssue', 'auth.login'], 'statuses' => [], 'categories' => ['auth', 'change'], 'sources' => ['mcp', 'ui']]);
});

it('returns empty option lists for an empty log', function (): void {
    expect(app(DescribeActivityFilterOptions::class)->handle(ListActivityLog::MCP_CALLS))->toBe(['types' => [], 'statuses' => [], 'categories' => [], 'sources' => []]);
});

it('refuses an unknown log name', function (): void {
    expect(fn () => app(DescribeActivityFilterOptions::class)->handle('everything'))
        ->toThrow(InvalidArgumentException::class, 'Unknown activity log "everything"');
});
