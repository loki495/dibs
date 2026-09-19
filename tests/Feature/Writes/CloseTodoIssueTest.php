<?php

declare(strict_types=1);

use App\Actions\CloseTodoIssue;
use App\Exceptions\TodoRecordUnavailableException;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use Illuminate\Support\Facades\Http;

it('closes the issue locally and enqueues the GitHub close without any network call', function (): void {
    Http::fake();
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    $closed = app(CloseTodoIssue::class)->handle($issue);

    expect($closed->state)->toBe('CLOSED')->and($issue->refresh()->state)->toBe('CLOSED');
    expect(GitHubPushQueueItem::query()->where('operation', 'close_issue')->where('target_id', $issue->id)->where('status', 'pending')->count())->toBe(1);
    Http::assertNothingSent();
});

it('is safe to repeat: closing an already-closed issue leaves a single queued push', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    app(CloseTodoIssue::class)->handle($issue);
    app(CloseTodoIssue::class)->handle($issue->refresh());

    expect($issue->refresh()->state)->toBe('CLOSED');
    expect(GitHubPushQueueItem::query()->where('operation', 'close_issue')->where('target_id', $issue->id)->count())->toBe(1);
});

it('refuses an unavailable issue with a specific error and changes nothing', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN', 'is_available' => false]);

    expect(fn () => app(CloseTodoIssue::class)->handle($issue))
        ->toThrow(TodoRecordUnavailableException::class, "Issue #{$issue->github_number} (local id {$issue->id}) is no longer available; it was removed or lost GitHub access.");

    expect($issue->refresh()->state)->toBe('OPEN');
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});
