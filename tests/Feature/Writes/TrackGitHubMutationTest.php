<?php

declare(strict_types=1);

use App\Actions\TrackGitHubMutation;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;

it('records intent before confirming a GitHub mutation', function (): void {
    $issue = Issue::factory()->create();
    $tracker = app(TrackGitHubMutation::class);

    $mutation = $tracker->begin('issue.update', $issue, ['title' => 'New title']);
    $tracker->confirm($mutation);

    expect($mutation->fresh()->status)->toBe('confirmed')->and($mutation->fresh()->confirmed_at)->not->toBeNull();
});

it('marks a network-ambiguous mutation for reconciliation while ordinary rejections remain failed', function (): void {
    $tracker = app(TrackGitHubMutation::class);
    $ambiguous = $tracker->begin('issue.create', null, ['title' => 'New task']);
    $rejected = $tracker->begin('issue.update', Issue::factory()->create(), ['title' => 'New title']);

    $tracker->fail($ambiguous, new GitHubSyncException('GitHub connection failed; the previous snapshot was preserved.'));
    $tracker->fail($rejected, new GitHubSyncException('A task title is required.'));

    expect($ambiguous->fresh()->status)->toBe('reconciliation_needed')->and($ambiguous->fresh()->requires_reconciliation)->toBeTrue()
        ->and($rejected->fresh()->status)->toBe('failed')->and($rejected->fresh()->requires_reconciliation)->toBeFalse();
});
