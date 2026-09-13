<?php

declare(strict_types=1);

use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;

beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

it('creates a task locally and enqueues the GitHub push', function (): void {
    $this->artisan('todo:agent:create', ['title' => 'Ship the thing', '--body' => 'Some detail'])
        ->expectsOutputToContain('Ship the thing')
        ->assertSuccessful();

    $issue = Issue::query()->where('title', 'Ship the thing')->sole();
    expect($issue->body)->toBe('Some detail')
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->where('target_id', $issue->id)->exists())->toBeTrue();
});

it('creates a task with a new label by name', function (): void {
    $this->artisan('todo:agent:create', ['title' => 'Tagged task', '--new-label' => 'urgent'])->assertSuccessful();

    $issue = Issue::query()->where('title', 'Tagged task')->sole();
    expect($issue->labels->pluck('name')->all())->toBe(['urgent']);
});

it('is idempotent: retrying the same key does not duplicate the task', function (): void {
    $this->artisan('todo:agent:create', ['title' => 'Once only', '--idempotency-key' => 'agent-1'])->assertSuccessful();
    $this->artisan('todo:agent:create', ['title' => 'Once only', '--idempotency-key' => 'agent-1'])->assertSuccessful();

    expect(Issue::query()->where('title', 'Once only')->count())->toBe(1);
});

it('returns a structured error for an unavailable area instead of crashing', function (): void {
    $this->artisan('todo:agent:create', ['title' => 'Task', '--area' => '999999'])->assertFailed();

    expect(Issue::query()->count())->toBe(0);
});

it('rejects a call missing the required title', function (): void {
    expect(fn () => $this->artisan('todo:agent:create', []))->toThrow(RuntimeException::class, 'title');
});
