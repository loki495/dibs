<?php

declare(strict_types=1);

use App\Services\GitHub\FetchGitHubSnapshot;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['github.owner' => 'loki495', 'github.repository' => 'dibs', 'github.projects' => [1]]);
});

it('fetches the repository, labels, issues, and projects into one snapshot', function (): void {
    Http::fakeSequence()
        // 1. repository
        ->push(['data' => ['viewer' => ['login' => 'loki495'], 'repository' => [
            'id' => 'R_1', 'name' => 'dibs', 'nameWithOwner' => 'loki495/dibs', 'url' => 'https://github.test/dibs',
            'isPrivate' => false, 'visibility' => 'PUBLIC', 'updatedAt' => '2026-09-20T00:00:00Z', 'owner' => ['login' => 'loki495'],
        ]]])
        // 2. repository labels
        ->push(['data' => ['node' => ['labels' => ['nodes' => [['id' => 'LA_1', 'name' => 'bug', 'color' => 'red', 'description' => null]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])
        // 3. repository issues, with embedded labels/subIssues already at their last page
        ->push(['data' => ['node' => ['issues' => ['nodes' => [[
            'id' => 'I_1', 'number' => 1, 'title' => 'Task one', 'body' => null, 'state' => 'OPEN', 'stateReason' => null,
            'url' => 'https://github.test/issues/1', 'updatedAt' => '2026-09-20T00:00:00Z', 'parent' => null,
            'labels' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'subIssues' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])
        // 4. project
        ->push(['data' => ['user' => ['projectV2' => ['id' => 'PVT_1', 'number' => 1, 'title' => 'Personal Projects', 'url' => 'https://github.test/projects/1', 'closed' => false, 'public' => false, 'updatedAt' => '2026-09-20T00:00:00Z']]]])
        // 5. project fields
        ->push(['data' => ['node' => ['fields' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])
        // 6. project items, with embedded fieldValues already at their last page
        ->push(['data' => ['node' => ['items' => ['nodes' => [[
            'id' => 'PVTI_1', 'type' => 'ISSUE', 'isArchived' => false, 'updatedAt' => '2026-09-20T00:00:00Z',
            'content' => ['__typename' => 'Issue', 'id' => 'I_1', 'title' => 'Task one', 'url' => 'https://github.test/issues/1', 'repository' => ['nameWithOwner' => 'loki495/dibs']],
            'fieldValues' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]]);

    $snapshot = app(FetchGitHubSnapshot::class)->handle(new GitHubClient('test-token'));

    expect($snapshot['repository']['nameWithOwner'])->toBe('loki495/dibs')
        ->and($snapshot['labels'])->toHaveCount(1)
        ->and($snapshot['issues'])->toHaveCount(1)
        ->and($snapshot['issues'][0]['labels'])->toBe([])
        ->and($snapshot['issues'][0]['children'])->toBe([])
        ->and($snapshot['issues'][0])->not->toHaveKey('subIssues')
        ->and($snapshot['issues'][0])->not->toHaveKey('comments')
        ->and($snapshot['projects'])->toHaveCount(1)
        ->and($snapshot['projects'][0]['owner'])->toBe('loki495')
        ->and($snapshot['projects'][0]['items'][0]['values'])->toBe([])
        ->and($snapshot['projects'][0]['items'][0])->not->toHaveKey('fieldValues');
    Http::assertSentCount(6);
});

it('also fetches issue comments when requested', function (): void {
    Http::fakeSequence()
        ->push(['data' => ['viewer' => ['login' => 'loki495'], 'repository' => ['id' => 'R_1', 'name' => 'dibs', 'nameWithOwner' => 'loki495/dibs', 'url' => null, 'isPrivate' => false, 'visibility' => 'PUBLIC', 'updatedAt' => null, 'owner' => ['login' => 'loki495']]]])
        ->push(['data' => ['node' => ['labels' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])
        ->push(['data' => ['node' => ['issues' => ['nodes' => [[
            'id' => 'I_1', 'number' => 1, 'title' => 'Task one', 'body' => null, 'state' => 'OPEN', 'stateReason' => null, 'url' => null, 'updatedAt' => null, 'parent' => null,
            'labels' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'subIssues' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])
        ->push(['data' => ['node' => ['comments' => ['nodes' => [['id' => 'IC_1', 'body' => 'A comment', 'url' => null, 'createdAt' => null, 'updatedAt' => null, 'author' => ['login' => 'octocat']]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])
        ->push(['data' => ['user' => ['projectV2' => ['id' => 'PVT_1', 'number' => 1, 'title' => 'x', 'url' => null, 'closed' => false, 'public' => false, 'updatedAt' => null]]]])
        ->push(['data' => ['node' => ['fields' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])
        ->push(['data' => ['node' => ['items' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]]);

    $snapshot = app(FetchGitHubSnapshot::class)->handle(new GitHubClient('test-token'), comments: true);

    expect($snapshot['issues'][0]['comments'])->toHaveCount(1)
        ->and($snapshot['issues'][0]['comments'][0]['body'])->toBe('A comment');
});

it('rejects when the configured repository is unavailable or does not match', function (): void {
    Http::fake(['*' => Http::response(['data' => ['viewer' => ['login' => 'loki495'], 'repository' => null]], 200)]);

    expect(fn () => app(FetchGitHubSnapshot::class)->handle(new GitHubClient('test-token')))
        ->toThrow(GitHubSyncException::class, 'unavailable or does not match');
});

it('rejects when the response repository does not match the configured owner/name', function (): void {
    Http::fake(['*' => Http::response(['data' => ['viewer' => ['login' => 'loki495'], 'repository' => ['id' => 'R_1', 'nameWithOwner' => 'someone-else/other-repo']]], 200)]);

    expect(fn () => app(FetchGitHubSnapshot::class)->handle(new GitHubClient('test-token')))
        ->toThrow(GitHubSyncException::class, 'unavailable or does not match');
});

it('preserves the previous snapshot when a configured project is unavailable', function (): void {
    Http::fakeSequence()
        ->push(['data' => ['viewer' => ['login' => 'loki495'], 'repository' => ['id' => 'R_1', 'name' => 'dibs', 'nameWithOwner' => 'loki495/dibs', 'url' => null, 'isPrivate' => false, 'visibility' => 'PUBLIC', 'updatedAt' => null, 'owner' => ['login' => 'loki495']]]])
        ->push(['data' => ['node' => ['labels' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])
        ->push(['data' => ['node' => ['issues' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])
        ->push(['data' => ['user' => ['projectV2' => null]]]);

    expect(fn () => app(FetchGitHubSnapshot::class)->handle(new GitHubClient('test-token')))
        ->toThrow(GitHubSyncException::class, 'previous snapshot was preserved');
});

it('rejects when the response project number does not match the requested one', function (): void {
    Http::fakeSequence()
        ->push(['data' => ['viewer' => ['login' => 'loki495'], 'repository' => ['id' => 'R_1', 'name' => 'dibs', 'nameWithOwner' => 'loki495/dibs', 'url' => null, 'isPrivate' => false, 'visibility' => 'PUBLIC', 'updatedAt' => null, 'owner' => ['login' => 'loki495']]]])
        ->push(['data' => ['node' => ['labels' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])
        ->push(['data' => ['node' => ['issues' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]])
        ->push(['data' => ['user' => ['projectV2' => ['id' => 'PVT_1', 'number' => 999]]]]);

    expect(fn () => app(FetchGitHubSnapshot::class)->handle(new GitHubClient('test-token')))
        ->toThrow(GitHubSyncException::class, 'previous snapshot was preserved');
});
