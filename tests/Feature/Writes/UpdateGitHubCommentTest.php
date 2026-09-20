<?php

declare(strict_types=1);

use App\Actions\UpdateGitHubComment;
use App\Models\Comment;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('updates a comment in GitHub before projecting its body locally', function (): void {
    $comment = Comment::factory()->create(['github_node_id' => 'IC_1', 'is_available' => true, 'body' => 'Old body']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['updateIssueComment' => ['issueComment' => [
        'id' => 'IC_1', 'body' => 'New body', 'url' => 'https://github.test/issues/1#issuecomment-1',
        'createdAt' => '2026-09-09T23:00:00Z', 'updatedAt' => '2026-09-10T00:00:00Z', 'author' => ['login' => 'octocat'],
    ]]]], 200));

    $updated = app(UpdateGitHubComment::class)->handle('test-token', $comment, ' New body ');

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'updateIssueComment')
        && (array) $request->data()['variables'] === ['commentId' => 'IC_1', 'body' => 'New body']);
    expect($updated->body)->toBe('New body')
        ->and($updated->author_login)->toBe('octocat');
});

it('rejects an empty body before contacting GitHub', function (): void {
    Http::fake();
    $comment = Comment::factory()->create(['is_available' => true, 'github_node_id' => 'IC_1']);

    expect(fn () => app(UpdateGitHubComment::class)->handle('test-token', $comment, '   '))
        ->toThrow(GitHubSyncException::class, 'cannot be empty');
    Http::assertNothingSent();
});

it('rejects a comment that is no longer available in the local snapshot', function (): void {
    Http::fake();
    $comment = Comment::factory()->create(['is_available' => false, 'github_node_id' => 'IC_1']);

    expect(fn () => app(UpdateGitHubComment::class)->handle('test-token', $comment, 'New body'))
        ->toThrow(GitHubSyncException::class, 'not available in the local snapshot');
    Http::assertNothingSent();
});

it('rejects when github does not confirm the update', function (): void {
    $comment = Comment::factory()->create(['github_node_id' => 'IC_1', 'is_available' => true]);
    Http::fake(fn () => Http::response(['data' => ['updateIssueComment' => ['issueComment' => null]]], 200));

    expect(fn () => app(UpdateGitHubComment::class)->handle('test-token', $comment, 'New body'))
        ->toThrow(GitHubSyncException::class, 'did not confirm');
});
