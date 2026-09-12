<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class CreateGitHubIssue
{
    public function handle(#[SensitiveParameter] string $token, string $title, ?string $body = null): Issue
    {
        $title = trim($title);
        if ($title === '') {
            throw new GitHubSyncException('A task title is required.');
        }

        $repository = GitHubRepository::query()->where('full_name', config('github.owner').'/'.config('github.repository'))->first();
        if (! $repository instanceof GitHubRepository || ! $repository->is_available) {
            throw new GitHubSyncException('The configured repository is not available in the local snapshot. Refresh and try again.');
        }

        $data = (new GitHubClient($token))->query(
            'mutation($repositoryId: ID!, $title: String!, $body: String) { createIssue(input: {repositoryId: $repositoryId, title: $title, body: $body}) { issue { id number title body state stateReason url updatedAt } } }',
            ['repositoryId' => $repository->github_node_id, 'title' => $title, 'body' => $body],
        );
        $remote = $data['createIssue']['issue'] ?? null;
        if (! is_array($remote) || ! is_string($remote['id'] ?? null) || ! is_int($remote['number'] ?? null)) {
            throw new GitHubSyncException('GitHub did not return the created issue. Refresh before retrying.');
        }

        return DB::transaction(fn (): Issue => Issue::query()->updateOrCreate(
            ['github_node_id' => $remote['id']],
            ['repository_id' => $repository->id, 'github_number' => $remote['number'], 'title' => $remote['title'], 'body' => $remote['body'] ?? null, 'state' => $remote['state'], 'state_reason' => $remote['stateReason'] ?? null, 'url' => $remote['url'] ?? null, 'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'is_available' => true, 'last_seen_at' => now()],
        ));
    }
}
