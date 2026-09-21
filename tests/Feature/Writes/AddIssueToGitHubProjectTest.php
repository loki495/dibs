<?php

declare(strict_types=1);

use App\Actions\AddIssueToGitHubProject;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('adds an existing issue to a GitHub Project and projects the membership locally', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_task']);
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_area']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['addProjectV2ItemById' => ['item' => ['id' => 'PVTI_membership']]]], 200));

    $item = app(AddIssueToGitHubProject::class)->handle('test-token', $issue, $project);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $variables = (array) $data['variables'];

        return str_contains((string) $data['query'], 'addProjectV2ItemById')
            && $variables['projectId'] === 'PVT_area'
            && $variables['contentId'] === 'I_task';
    });
    expect($item->project_id)->toBe($project->id)
        ->and($item->issue_id)->toBe($issue->id)
        ->and($item->github_node_id)->toBe('PVTI_membership')
        ->and($item->content_type)->toBe('ISSUE');
});

it('rejects when github does not return the project membership', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_task']);
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_area']);
    Http::fake(fn () => Http::response(['data' => ['addProjectV2ItemById' => ['item' => null]]], 200));

    expect(fn () => app(AddIssueToGitHubProject::class)->handle('test-token', $issue, $project))
        ->toThrow(GitHubSyncException::class, 'did not return the Project membership');
});

it('rejects an unavailable Project before making a GitHub request', function (): void {
    Http::fake();
    $issue = Issue::factory()->create();
    $project = GitHubProject::factory()->create(['is_available' => false]);

    expect(fn () => app(AddIssueToGitHubProject::class)->handle('test-token', $issue, $project))
        ->toThrow(GitHubSyncException::class, 'not available');
    Http::assertNothingSent();
});
