<?php

declare(strict_types=1);

use App\Actions\UpdateGitHubProject;
use App\Models\GitHubProject;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('updates a project title in GitHub before projecting it locally', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_area', 'title' => 'Old name']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['updateProjectV2' => ['projectV2' => ['id' => 'PVT_area', 'title' => 'New name', 'updatedAt' => '2026-09-10T00:00:00Z']]]], 200));

    $updated = app(UpdateGitHubProject::class)->handle('test-token', $project, ' New name ');

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'updateProjectV2')
        && (array) $request->data()['variables'] === ['projectId' => 'PVT_area', 'title' => 'New name']);
    expect($updated->title)->toBe('New name')->and($updated->remote_updated_at?->toAtomString())->toBe('2026-09-10T00:00:00+00:00');
});

it('rejects an empty project title before contacting GitHub', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();

    expect(fn () => app(UpdateGitHubProject::class)->handle('test-token', $project, '   '))
        ->toThrow(GitHubSyncException::class, 'project name is required');
    Http::assertNothingSent();
});
