<?php

declare(strict_types=1);

use App\Actions\ReopenTodoIssue;
use App\Exceptions\TodoRecordUnavailableException;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use Illuminate\Support\Facades\Http;

it('reopens a closed issue locally, clears the reason, and enqueues the GitHub reopen without any network call', function (): void {
    Http::fake();
    $issue = Issue::factory()->create(['state' => 'CLOSED', 'state_reason' => 'COMPLETED']);

    $reopened = app(ReopenTodoIssue::class)->handle($issue);

    expect($reopened->state)->toBe('OPEN')->state_reason->toBeNull()
        ->and($issue->refresh()->state)->toBe('OPEN')->state_reason->toBeNull();
    expect(GitHubPushQueueItem::query()->where('operation', 'reopen_issue')->where('target_id', $issue->id)->where('status', 'pending')->count())->toBe(1);
    Http::assertNothingSent();
});

it('is safe to repeat: reopening an already-open issue leaves a single queued push', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    app(ReopenTodoIssue::class)->handle($issue);
    app(ReopenTodoIssue::class)->handle($issue->refresh());

    expect($issue->refresh()->state)->toBe('OPEN');
    expect(GitHubPushQueueItem::query()->where('operation', 'reopen_issue')->where('target_id', $issue->id)->count())->toBe(1);
});

it('refuses an unavailable issue with a specific error and changes nothing', function (): void {
    $issue = Issue::factory()->create(['state' => 'CLOSED', 'is_available' => false]);

    expect(fn () => app(ReopenTodoIssue::class)->handle($issue))
        ->toThrow(TodoRecordUnavailableException::class, "Issue #{$issue->github_number} (local id {$issue->id}) is no longer available; it was removed or lost GitHub access.");

    expect($issue->refresh()->state)->toBe('CLOSED');
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});
