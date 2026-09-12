<?php

declare(strict_types=1);

use App\Actions\CreateGitHubComment;
use App\Models\Comment;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;

it('adds a GitHub-first agent comment and returns JSON', function (): void {
    config(['github.token' => 'test-token']);
    $issue = Issue::factory()->create();
    $comment = Comment::factory()->for($issue, 'issue')->create();
    $action = Mockery::mock(CreateGitHubComment::class);
    $action->shouldReceive('handle')->once()->with('test-token', Mockery::on(fn (Issue $candidate): bool => $candidate->is($issue)), 'Checkpoint')->andReturn($comment);
    app()->instance(CreateGitHubComment::class, $action);

    $this->artisan('todo:agent:comment', ['issue' => $issue->id, 'body' => 'Checkpoint'])->expectsOutputToContain('comment_id')->assertSuccessful();
});

it('reports a GitHub comment failure without crashing', function (): void {
    config(['github.token' => 'test-token']);
    $issue = Issue::factory()->create();
    $action = Mockery::mock(CreateGitHubComment::class);
    $action->shouldReceive('handle')->once()->andThrow(new GitHubSyncException('GitHub unavailable'));
    app()->instance(CreateGitHubComment::class, $action);

    $this->artisan('todo:agent:comment', ['issue' => $issue->id, 'body' => 'Checkpoint'])->expectsOutput('GitHub unavailable')->assertFailed();
});
