<?php

declare(strict_types=1);

use App\Actions\DeleteGitHubIssue;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('deletes an unparented issue from GitHub before marking the local projection unavailable', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_container']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['deleteIssue' => ['clientMutationId' => null]]], 200));

    $deleted = app(DeleteGitHubIssue::class)->handle('test-token', $issue);

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'deleteIssue')
        && (array) $request->data()['variables'] === ['issueId' => 'I_container']);
    expect($deleted->is_available)->toBeFalse();
});

it('refuses to delete an unavailable issue', function (): void {
    Http::fake();
    $issue = Issue::factory()->create(['is_available' => false]);

    expect(fn () => app(DeleteGitHubIssue::class)->handle('test-token', $issue))
        ->toThrow(GitHubSyncException::class, 'not available in the local snapshot');
    Http::assertNothingSent();
});

it('refuses to delete an issue that still has available children', function (): void {
    Http::fake();
    $parent = Issue::factory()->create();
    Issue::factory()->for($parent, 'parent')->for($parent->repository, 'repository')->create();

    expect(fn () => app(DeleteGitHubIssue::class)->handle('test-token', $parent))
        ->toThrow(GitHubSyncException::class, 'children');
    Http::assertNothingSent();
});
