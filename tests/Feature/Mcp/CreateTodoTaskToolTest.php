<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\CreateTodoTask;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;

beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

it('exposes the tool under the todo_create name', function (): void {
    expect(app(CreateTodoTask::class)->name())->toBe('todo_create');
});

it('creates a task through the todo_create tool and returns full detail', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Ship the thing', 'body' => 'Some detail'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Ship the thing')
        ->assertSee('Some detail');

    expect(Issue::query()->where('title', 'Ship the thing')->exists())->toBeTrue()
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->exists())->toBeTrue();
});

it('returns a structured error for an unavailable area instead of crashing', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Task', 'area' => 999_999])
        ->assertHasErrors();

    expect(Issue::query()->count())->toBe(0);
});

it('rejects a call missing the required title', function (): void {
    TodoServer::tool(CreateTodoTask::class, [])
        ->assertHasErrors();
});

it('is idempotent through the tool: retrying the same key does not duplicate the task', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Once only', 'idempotencyKey' => 'agent-1'])->assertOk();
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Once only', 'idempotencyKey' => 'agent-1'])->assertOk();

    expect(Issue::query()->where('title', 'Once only')->count())->toBe(1);
});
