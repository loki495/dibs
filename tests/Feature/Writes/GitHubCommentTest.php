<?php

declare(strict_types=1);

use App\Actions\CreateGitHubComment;
use App\Actions\UpdateGitHubComment;
use App\Models\Comment;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('adds a comment in GitHub before saving its local projection', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_task']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['addComment' => ['commentEdge' => ['node' => ['id' => 'IC_new', 'body' => 'A note', 'url' => 'https://github.test/comment', 'createdAt' => '2026-09-09T23:00:00Z', 'updatedAt' => '2026-09-09T23:00:00Z', 'author' => ['login' => 'andres']]]]]], 200));

    $comment = app(CreateGitHubComment::class)->handle('test-token', $issue, ' A note ');

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'addComment')
        && (array) $request->data()['variables'] === ['subjectId' => 'I_task', 'body' => 'A note']);
    expect($comment->body)->toBe('A note')->and($comment->issue_id)->toBe($issue->id)->and($comment->author_login)->toBe('andres');
});

it('updates a saved comment only after GitHub confirms it', function (): void {
    $comment = Comment::factory()->create(['github_node_id' => 'IC_old', 'body' => 'Old']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['updateIssueComment' => ['issueComment' => ['id' => 'IC_old', 'body' => 'New', 'url' => 'https://github.test/comment', 'createdAt' => '2026-09-09T22:00:00Z', 'updatedAt' => '2026-09-09T23:00:00Z', 'author' => ['login' => 'andres']]]]], 200));

    $updated = app(UpdateGitHubComment::class)->handle('test-token', $comment, ' New ');

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'updateIssueComment')
        && (array) $request->data()['variables'] === ['commentId' => 'IC_old', 'body' => 'New']);
    expect($updated->body)->toBe('New')->and($updated->author_login)->toBe('andres');
});

it('rejects blank comments without contacting GitHub', function (): void {
    Http::fake();
    $issue = Issue::factory()->create();

    expect(fn () => app(CreateGitHubComment::class)->handle('test-token', $issue, '  '))
        ->toThrow(GitHubSyncException::class, 'comment cannot be empty');
    Http::assertNothingSent();
});
