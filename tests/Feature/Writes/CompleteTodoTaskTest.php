<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\CompleteTodoTask;
use App\Exceptions\TodoRecordUnavailableException;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\TaskClaim;

it('closes the issue, enqueues the push, posts a summary, and releases the claim', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    $completed = app(CompleteTodoTask::class)->handle($issue->id, getmypid(), $result['capability_token'], 'Shipped the fix.');

    expect($completed->state)->toBe('CLOSED')
        ->and(GitHubPushQueueItem::query()->where('operation', 'close_issue')->where('target_id', $issue->id)->exists())->toBeTrue()
        ->and(Comment::query()->where('issue_id', $issue->id)->sole()->body)->toBe('**Completed:** Shipped the fix.')
        ->and(TaskClaim::query()->sole()->released_at)->not->toBeNull();
});

it('completes without a summary and posts no comment', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    app(CompleteTodoTask::class)->handle($issue->id, getmypid(), $result['capability_token']);

    expect(Comment::query()->where('issue_id', $issue->id)->count())->toBe(0);
});

it('rejects completion from a process that does not hold the claim — a worker cannot impersonate another', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    expect(fn () => app(CompleteTodoTask::class)->handle($issue->id, getmypid(), 'wrong-token'))
        ->toThrow(DomainException::class, 'No live claim');
    expect($issue->fresh()->state)->toBe('OPEN');
});

it('rejects completion once the claim has already been released', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    TaskClaim::query()->sole()->update(['released_at' => now()]);

    expect(fn () => app(CompleteTodoTask::class)->handle($issue->id, getmypid(), $result['capability_token']))
        ->toThrow(DomainException::class, 'No live claim');
});

it('rejects completing an issue that is no longer available', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    $issue->update(['is_available' => false]);

    expect(fn () => app(CompleteTodoTask::class)->handle($issue->id, getmypid(), $result['capability_token']))
        ->toThrow(TodoRecordUnavailableException::class);
});
