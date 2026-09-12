<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProjectItem;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class ClearProjectItemPriority
{
    public function handle(#[SensitiveParameter] string $token, ProjectItem $item): ProjectItem
    {
        $item->loadMissing('project', 'priorityOption.field');
        if ($item->priority_option_id === null) {
            return $item;
        }
        if (! $item->is_available || $item->github_node_id === '' || ! $item->project?->is_available || ! $item->priorityOption?->field?->is_available) {
            throw new GitHubSyncException('The selected Priority assignment is not available locally. Refresh and try again.');
        }
        $data = (new GitHubClient($token))->query(
            'mutation($projectId: ID!, $itemId: ID!, $fieldId: ID!) { clearProjectV2ItemFieldValue(input: {projectId: $projectId, itemId: $itemId, fieldId: $fieldId}) { projectV2Item { id updatedAt } } }',
            ['projectId' => $item->project->github_node_id, 'itemId' => $item->github_node_id, 'fieldId' => $item->priorityOption->field->github_node_id],
        );
        $remote = $data['clearProjectV2ItemFieldValue']['projectV2Item'] ?? null;
        if (! is_array($remote) || ($remote['id'] ?? null) !== $item->github_node_id) {
            throw new GitHubSyncException('GitHub did not confirm removal of the Priority. Refresh before retrying.');
        }

        return DB::transaction(function () use ($item, $remote): ProjectItem {
            $item->update(['priority_option_id' => null, 'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now()]);

            return $item->refresh();
        });
    }
}
