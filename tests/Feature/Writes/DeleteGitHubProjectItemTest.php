<?php

declare(strict_types=1);

use App\Actions\DeleteGitHubProjectItem;
use App\Models\GitHubProject;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('deletes a project item from GitHub before marking the local projection unavailable', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_area']);
    $item = ProjectItem::factory()->for($project, 'project')->create(['github_node_id' => 'PVTI_item']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['deleteProjectV2Item' => ['deletedItemId' => 'PVTI_item']]], 200));

    $deleted = app(DeleteGitHubProjectItem::class)->handle('test-token', $item);

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'deleteProjectV2Item')
        && (array) $request->data()['variables'] === ['projectId' => 'PVT_area', 'itemId' => 'PVTI_item']);
    expect($deleted->is_available)->toBeFalse();
});

it('refuses an unavailable project membership before contacting GitHub', function (): void {
    Http::fake();
    $item = ProjectItem::factory()->create(['is_available' => false]);

    expect(fn () => app(DeleteGitHubProjectItem::class)->handle('test-token', $item))
        ->toThrow(GitHubSyncException::class, 'not available locally');
    Http::assertNothingSent();
});

it('rejects when github does not confirm removal from the project', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_area']);
    $item = ProjectItem::factory()->for($project, 'project')->create(['github_node_id' => 'PVTI_item']);
    Http::fake(fn () => Http::response(['data' => ['deleteProjectV2Item' => ['deletedItemId' => null]]], 200));

    expect(fn () => app(DeleteGitHubProjectItem::class)->handle('test-token', $item))
        ->toThrow(GitHubSyncException::class, 'did not confirm');
});
