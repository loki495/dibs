<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class SetProjectItemPriority
{
    public function handle(#[SensitiveParameter] string $token, ProjectItem $item, ProjectFieldOption $priority): ProjectItem
    {
        $item->loadMissing('project');
        $priority->loadMissing('field');
        if (! $item->is_available || $item->github_node_id === '' || ! $item->project?->is_available) {
            throw new GitHubSyncException('The selected Project item is not available locally. Refresh and try again.');
        }
        if (! $priority->field instanceof ProjectField || ! $priority->field->is_available || $priority->field->semantic_key !== 'priority' || $priority->field->project_id !== $item->project_id) {
            throw new GitHubSyncException('A task can only use a Priority from the same Project.');
        }
        if ($item->priority_option_id === $priority->id) {
            return $item;
        }

        $data = (new GitHubClient($token))->query(
            'mutation($projectId: ID!, $itemId: ID!, $fieldId: ID!, $optionId: String!) { updateProjectV2ItemFieldValue(input: {projectId: $projectId, itemId: $itemId, fieldId: $fieldId, value: {singleSelectOptionId: $optionId}}) { projectV2Item { id updatedAt } } }',
            ['projectId' => $item->project->github_node_id, 'itemId' => $item->github_node_id, 'fieldId' => $priority->field->github_node_id, 'optionId' => $priority->github_option_id],
        );
        $remote = $data['updateProjectV2ItemFieldValue']['projectV2Item'] ?? null;
        if (! is_array($remote) || ($remote['id'] ?? null) !== $item->github_node_id) {
            throw new GitHubSyncException('GitHub did not confirm the Priority assignment. Refresh before retrying.');
        }

        return DB::transaction(function () use ($item, $priority, $remote): ProjectItem {
            $item->update(['priority_option_id' => $priority->id, 'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now()]);

            return $item->refresh();
        });
    }
}
