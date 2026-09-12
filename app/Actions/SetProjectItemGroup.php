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

class SetProjectItemGroup
{
    public function handle(#[SensitiveParameter] string $token, ProjectItem $item, ProjectFieldOption $group): ProjectItem
    {
        $item->loadMissing('project');
        $group->loadMissing('field');
        if (! $item->is_available || $item->github_node_id === '' || ! $item->project?->is_available) {
            throw new GitHubSyncException('The selected Project item is not available locally. Refresh and try again.');
        }
        if (! $group->field instanceof ProjectField || ! $group->field->is_available || $group->field->semantic_key !== 'group' || $group->field->project_id !== $item->project_id) {
            throw new GitHubSyncException('A task can only use a Group from the same Project.');
        }
        if ($item->group_option_id === $group->id) {
            return $item;
        }

        $data = (new GitHubClient($token))->query(
            'mutation($projectId: ID!, $itemId: ID!, $fieldId: ID!, $optionId: String!) { updateProjectV2ItemFieldValue(input: {projectId: $projectId, itemId: $itemId, fieldId: $fieldId, value: {singleSelectOptionId: $optionId}}) { projectV2Item { id updatedAt } } }',
            ['projectId' => $item->project->github_node_id, 'itemId' => $item->github_node_id, 'fieldId' => $group->field->github_node_id, 'optionId' => $group->github_option_id],
        );
        $remote = $data['updateProjectV2ItemFieldValue']['projectV2Item'] ?? null;
        if (! is_array($remote) || ($remote['id'] ?? null) !== $item->github_node_id) {
            throw new GitHubSyncException('GitHub did not confirm the Group assignment. Refresh before retrying.');
        }

        return DB::transaction(function () use ($item, $group, $remote): ProjectItem {
            $item->update(['group_option_id' => $group->id, 'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now()]);

            return $item->refresh();
        });
    }
}
