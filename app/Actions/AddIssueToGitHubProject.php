<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class AddIssueToGitHubProject
{
    public function handle(#[SensitiveParameter] string $token, Issue $issue, GitHubProject $project): ProjectItem
    {
        if (! $issue->is_available || ! $project->is_available || $issue->github_node_id === '' || $project->github_node_id === '') {
            throw new GitHubSyncException('The selected task or area is not available in the local snapshot. Refresh and try again.');
        }

        $data = (new GitHubClient($token))->query(
            'mutation($projectId: ID!, $contentId: ID!) { addProjectV2ItemById(input: {projectId: $projectId, contentId: $contentId}) { item { id updatedAt } } }',
            ['projectId' => $project->github_node_id, 'contentId' => $issue->github_node_id],
        );
        $remote = $data['addProjectV2ItemById']['item'] ?? null;
        if (! is_array($remote) || ! is_string($remote['id'] ?? null)) {
            throw new GitHubSyncException('GitHub did not return the Project membership. Refresh before retrying.');
        }

        return DB::transaction(fn (): ProjectItem => ProjectItem::query()->updateOrCreate(
            ['project_id' => $project->id, 'issue_id' => $issue->id],
            ['github_node_id' => $remote['id'], 'content_type' => 'ISSUE', 'archived_at' => null,
                'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'is_available' => true, 'last_seen_at' => now()],
        ));
    }
}
