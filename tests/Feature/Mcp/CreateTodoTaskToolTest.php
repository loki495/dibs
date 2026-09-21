<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\CreateTodoTask;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;

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

it('attaches several labels by name, reusing existing ones case-insensitively and creating the rest', function (): void {
    $repository = GitHubRepository::query()->firstOrFail();
    $existing = Label::factory()->create(['repository_id' => $repository->id, 'name' => 'agent task']);

    TodoServer::tool(CreateTodoTask::class, ['title' => 'Labelled', 'labelNames' => ['Agent Task', 'bug', 'brand new']])
        ->assertOk()
        ->assertHasNoErrors();

    $issue = Issue::query()->where('title', 'Labelled')->firstOrFail();

    expect($issue->labels->pluck('name')->sort()->values()->all())->toBe(['agent task', 'brand new', 'bug'])
        ->and($issue->labels->pluck('id'))->toContain($existing->id)
        ->and(Label::query()->where('repository_id', $repository->id)->whereRaw('LOWER(name) = ?', ['agent task'])->count())->toBe(1);
});

it('combines labelNames with labelIds and newLabelName without duplicating a label', function (): void {
    $repository = GitHubRepository::query()->firstOrFail();
    $byId = Label::factory()->create(['repository_id' => $repository->id, 'name' => 'feature request']);

    TodoServer::tool(CreateTodoTask::class, [
        'title' => 'Mixed',
        'labelIds' => [$byId->id],
        'newLabelName' => 'ui',
        'labelNames' => ['Feature Request', 'UI', 'bug'],
    ])->assertOk()->assertHasNoErrors();

    $issue = Issue::query()->where('title', 'Mixed')->firstOrFail();

    expect($issue->labels->pluck('name')->sort()->values()->all())->toBe(['bug', 'feature request', 'ui']);
});

it('ignores blank label names and de-duplicates repeats within one call', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Repeats', 'labelNames' => ['bug', 'Bug', '  ', '']])
        ->assertOk()
        ->assertHasNoErrors();

    $issue = Issue::query()->where('title', 'Repeats')->firstOrFail();

    expect($issue->labels->pluck('name')->all())->toBe(['bug'])
        ->and(Label::query()->whereRaw('LOWER(name) = ?', ['bug'])->count())->toBe(1);
});

it('rejects labelNames that is not an array of short strings without creating anything', function (mixed $labelNames): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Bad labels', 'labelNames' => $labelNames])
        ->assertHasErrors();

    expect(Issue::query()->where('title', 'Bad labels')->exists())->toBeFalse()
        ->and(Label::query()->count())->toBe(0);
})->with([
    'a bare string' => ['bug'],
    'a non-string item' => [[['nested']]],
    'an over-long name' => [[str_repeat('x', 51)]],
]);
