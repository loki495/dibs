<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProjectItem;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class DeleteGitHubProjectItem
{
    public function handle(#[SensitiveParameter] string $token, ProjectItem $item): ProjectItem
    {
        $item->loadMissing('project');
        if (! $item->is_available || $item->github_node_id === '' || ! $item->project?->is_available || $item->project->github_node_id === '') {
            throw new GitHubSyncException('The selected Project membership is not available locally. Refresh and try again.');
        }
        $data = (new GitHubClient($token))->query(
            'mutation($projectId: ID!, $itemId: ID!) { deleteProjectV2Item(input: {projectId: $projectId, itemId: $itemId}) { deletedItemId } }',
            ['projectId' => $item->project->github_node_id, 'itemId' => $item->github_node_id],
        );
        if (($data['deleteProjectV2Item']['deletedItemId'] ?? null) !== $item->github_node_id) {
            throw new GitHubSyncException('GitHub did not confirm removal from the Project. Refresh before retrying.');
        }

        return DB::transaction(function () use ($item): ProjectItem {
            $item->update(['is_available' => false, 'last_synced_at' => now(), 'last_seen_at' => now()]);

            return $item->refresh();
        });
    }
}
