<?php

declare(strict_types=1);

use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\Http;

it('collects every connection page', function (): void {
    Http::fakeSequence()->push(['data' => ['node' => ['issues' => ['nodes' => [['id' => 'I1']], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor1']]]]])
        ->push(['data' => ['node' => ['issues' => ['nodes' => [['id' => 'I2']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cursor2']]]]]);
    $client = new GitHubClient('test-token');
    expect($client->connection('R1', 'Repository', 'issues', 'id'))->toBe([['id' => 'I1'], ['id' => 'I2']]);
    Http::assertSentCount(2);
});

it('rejects partial GraphQL results instead of accepting an incomplete snapshot', function (): void {
    Http::fake(['*' => Http::response(['data' => ['node' => null], 'errors' => [['message' => 'private response detail']]], 200)]);
    expect(fn () => (new GitHubClient('test-token'))->query('query { viewer { login } }'))
        ->toThrow(GitHubSyncException::class, 'GitHub returned GraphQL errors');
});

it('reports rate limiting without leaking credentials or response bodies', function (): void {
    Http::fake(['*' => Http::response(['message' => 'private response detail'], 429, ['Retry-After' => '60'])]);
    expect(fn () => (new GitHubClient('test-token'))->query('query { viewer { login } }'))
        ->toThrow(GitHubSyncException::class, 'GitHub HTTP 429');
});

it('rejects a missing pagination cursor', function (): void {
    Http::fake(['*' => Http::response(['data' => ['node' => ['issues' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => null]]]]])]);
    expect(fn () => (new GitHubClient('test-token'))->connection('R1', 'Repository', 'issues', 'id'))
        ->toThrow(GitHubSyncException::class, 'Invalid pagination cursor');
});
