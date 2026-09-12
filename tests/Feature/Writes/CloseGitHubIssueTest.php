<?php

declare(strict_types=1);

use App\Actions\CloseGitHubIssue;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('closes an open issue in GitHub before projecting it locally', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_task']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['closeIssue' => ['issue' => ['id' => 'I_task', 'state' => 'CLOSED', 'stateReason' => 'COMPLETED', 'updatedAt' => '2026-09-09T23:00:00Z']]]], 200));

    $closed = app(CloseGitHubIssue::class)->handle('test-token', $issue);

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'closeIssue')
        && (array) $request->data()['variables'] === ['issueId' => 'I_task']);
    expect($closed->state)->toBe('CLOSED')->and($closed->state_reason)->toBe('COMPLETED');
});

it('does not close an already closed issue remotely', function (): void {
    Http::fake();
    $issue = Issue::factory()->create(['state' => 'CLOSED']);

    expect(fn () => app(CloseGitHubIssue::class)->handle('test-token', $issue))
        ->toThrow(GitHubSyncException::class, 'already closed');
    Http::assertNothingSent();
});
