<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubProject;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class UpdateGitHubProject
{
    public function handle(#[SensitiveParameter] string $token, GitHubProject $project, string $title): GitHubProject
    {
        $title = trim($title);
        if ($title === '') {
            throw new GitHubSyncException('A project name is required.');
        }
        if (! $project->is_available || $project->github_node_id === '') {
            throw new GitHubSyncException('The selected project is not available in the local snapshot. Refresh and try again.');
        }

        $data = (new GitHubClient($token))->query(
            'mutation($projectId: ID!, $title: String!) { updateProjectV2(input: {projectId: $projectId, title: $title}) { projectV2 { id title updatedAt } } }',
            ['projectId' => $project->github_node_id, 'title' => $title],
        );
        $remote = $data['updateProjectV2']['projectV2'] ?? null;
        if (! is_array($remote) || ($remote['id'] ?? null) !== $project->github_node_id || ! is_string($remote['title'] ?? null)) {
            throw new GitHubSyncException('GitHub did not confirm the project update. Refresh before retrying.');
        }

        return DB::transaction(function () use ($project, $remote): GitHubProject {
            $project->update(['title' => $remote['title'], 'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now()]);

            return $project->refresh();
        });
    }
}
