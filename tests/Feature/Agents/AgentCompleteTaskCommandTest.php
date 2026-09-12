<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;

it('completes a claimed task through the CLI as JSON', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    $this->artisan('todo:agent:complete', [
        'issue' => $issue->id,
        '--pid' => (string) getmypid(),
        '--token' => $result['capability_token'],
        '--summary' => 'Shipped it.',
    ])->expectsOutputToContain('issue_id')->assertSuccessful();

    expect($issue->fresh()->state)->toBe('CLOSED')
        ->and(GitHubPushQueueItem::query()->where('operation', 'close_issue')->exists())->toBeTrue()
        ->and(Comment::query()->where('issue_id', $issue->id)->sole()->body)->toBe('**Completed:** Shipped it.');
});

it('refuses completion without the matching capability token', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    $this->artisan('todo:agent:complete', [
        'issue' => $issue->id,
        '--pid' => (string) getmypid(),
        '--token' => 'wrong-token',
    ])->expectsOutput('No live claim for this task matches that capability token and process.')->assertFailed();

    expect($issue->fresh()->state)->toBe('OPEN');
});

it('rejects completion without a numeric --pid or --token', function (): void {
    $issue = Issue::factory()->create();

    $this->artisan('todo:agent:complete', ['issue' => $issue->id])
        ->expectsOutputToContain('numeric --pid')
        ->assertFailed();
});
