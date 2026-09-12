<?php

declare(strict_types=1);

use App\Actions\UpdateGitHubIssue;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('updates an issue in GitHub before projecting its title and description locally', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_task', 'title' => 'Old title', 'body' => 'Old body']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['updateIssue' => ['issue' => ['id' => 'I_task', 'title' => 'New title', 'body' => 'New **description**', 'state' => 'OPEN', 'stateReason' => null, 'url' => 'https://github.test/issues/1', 'updatedAt' => '2026-09-09T23:00:00Z']]]], 200));

    $updated = app(UpdateGitHubIssue::class)->handle('test-token', $issue, ' New title ', 'New **description**');

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'updateIssue')
        && (array) $request->data()['variables'] === ['issueId' => 'I_task', 'title' => 'New title', 'body' => 'New **description**']);
    expect($updated->title)->toBe('New title')->and($updated->body)->toBe('New **description**');
});

it('rejects an empty title before contacting GitHub', function (): void {
    Http::fake();
    $issue = Issue::factory()->create();

    expect(fn () => app(UpdateGitHubIssue::class)->handle('test-token', $issue, '   ', null))
        ->toThrow(GitHubSyncException::class, 'title is required');
    Http::assertNothingSent();
});
