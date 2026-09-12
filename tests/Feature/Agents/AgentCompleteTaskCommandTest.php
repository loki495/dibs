<?php

declare(strict_types=1);

use App\Actions\CloseGitHubIssue;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;

it('completes a task through the shared GitHub-first Action', function (): void {
    config(['github.token' => 'test-token']);
    $issue = Issue::factory()->create();
    $action = Mockery::mock(CloseGitHubIssue::class);
    $action->shouldReceive('handle')->once()->with('test-token', Mockery::on(fn (Issue $candidate): bool => $candidate->is($issue)))->andReturn($issue);
    app()->instance(CloseGitHubIssue::class, $action);

    $this->artisan('todo:agent:complete', ['issue' => $issue->id])->expectsOutputToContain('issue_id')->assertSuccessful();
});

it('reports a failed completion without crashing', function (): void {
    config(['github.token' => 'test-token']);
    $issue = Issue::factory()->create();
    $action = Mockery::mock(CloseGitHubIssue::class);
    $action->shouldReceive('handle')->once()->andThrow(new GitHubSyncException('GitHub unavailable'));
    app()->instance(CloseGitHubIssue::class, $action);

    $this->artisan('todo:agent:complete', ['issue' => $issue->id])->expectsOutput('GitHub unavailable')->assertFailed();
});
