<?php

declare(strict_types=1);

use App\Actions\CreateGitHubIssue;
use App\Models\GitHubRepository;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('creates on GitHub before adding the returned issue to the local snapshot', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => 'loki495/Todo', 'github_node_id' => 'R_test']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['createIssue' => ['issue' => ['id' => 'I_created', 'number' => 500, 'title' => 'Capture this', 'body' => null, 'state' => 'OPEN', 'stateReason' => null, 'url' => 'https://github.com/loki495/Todo/issues/500', 'updatedAt' => '2026-09-09T20:00:00Z']]]], 200));

    $issue = app(CreateGitHubIssue::class)->handle('test-token', '  Capture this  ');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $variables = (array) $data['variables'];

        return str_contains((string) $data['query'], 'createIssue') && $variables['repositoryId'] === 'R_test';
    });
    expect($issue->repository_id)->toBe($repository->id)->and($issue->github_number)->toBe(500)->and($issue->title)->toBe('Capture this');
});

it('does not make a network request for an empty title', function (): void {
    Http::fake();

    expect(fn () => app(CreateGitHubIssue::class)->handle('test-token', '   '))->toThrow(GitHubSyncException::class, 'title is required');
    Http::assertNothingSent();
});
