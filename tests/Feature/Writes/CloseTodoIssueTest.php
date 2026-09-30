<?php

declare(strict_types=1);

use App\Actions\CloseTodoIssue;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoValidationException;
use App\Models\Comment;
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

it('accepts a reason, sets state_reason, and enqueues it for GitHub', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    $closed = app(CloseTodoIssue::class)->handle($issue, reason: CloseTodoIssue::REASON_NOT_PLANNED);

    expect($closed->state_reason)->toBe('NOT_PLANNED')
        ->and(GitHubPushQueueItem::query()->where('operation', 'close_issue')->where('target_id', $issue->id)->sole()->payload)->toBe(['stateReason' => 'NOT_PLANNED']);
});

it('rejects a reason that is not one GitHub recognizes, changing nothing', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    expect(fn () => app(CloseTodoIssue::class)->handle($issue, reason: 'DONE'))
        ->toThrow(TodoValidationException::class, 'Reason must be one of: COMPLETED, NOT_PLANNED.');
    expect($issue->fresh())->state->toBe('OPEN')->state_reason->toBeNull();
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('creates a closing comment from the note and references when given', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    app(CloseTodoIssue::class)->handle($issue, reason: CloseTodoIssue::REASON_COMPLETED, note: 'All done.', references: ['#42']);

    $comment = Comment::query()->where('issue_id', $issue->id)->sole();
    expect($comment->kind)->toBe(Comment::KIND_CLOSING)->and($comment->body)->toBe('All done.')->and($comment->references)->toBe(['#42']);
});

it('creates no closing comment when neither a note nor references are given', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    app(CloseTodoIssue::class)->handle($issue);

    expect(Comment::query()->count())->toBe(0);
});

it('does not create a second closing comment when an already-closed issue is closed again, even with a note', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    app(CloseTodoIssue::class)->handle($issue, note: 'First close.');

    app(CloseTodoIssue::class)->handle($issue->fresh(), note: 'Second close attempt.');

    expect(Comment::query()->count())->toBe(1)
        ->and(Comment::query()->sole()->body)->toBe('First close.');
});

it('stamps closed_at when closing, and keeps the original stamp when closed again', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN', 'closed_at' => null]);

    app(CloseTodoIssue::class)->handle($issue);
    $first = $issue->refresh()->closed_at;
    $this->travel(2)->days();
    app(CloseTodoIssue::class)->handle($issue);

    expect($first)->not->toBeNull()->and($issue->refresh()->closed_at->equalTo($first))->toBeTrue();
});
