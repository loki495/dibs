<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubProject;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class EnsureGitHubGroupOption
{
    public function handle(#[SensitiveParameter] string $token, GitHubProject $project, string $name, string $color = 'GRAY'): ProjectFieldOption
    {
        $name = trim($name);
        if (! $project->is_available || $project->github_node_id === '' || $name === '') {
            throw new GitHubSyncException('The Project and a non-empty Group name are required. Refresh and try again.');
        }
        $field = $project->fields()->where('semantic_key', 'group')->where('is_available', true)->first();
        if (! $field instanceof ProjectField || $field->data_type !== 'SINGLE_SELECT') {
            throw new GitHubSyncException('This Project does not have an available Group field. Refresh and try again.');
        }
        $existing = $field->options()->get()->first(fn (ProjectFieldOption $option): bool => strcasecmp($option->name, $name) === 0);
        if ($existing instanceof ProjectFieldOption) {
            return $existing;
        }

        $client = new GitHubClient($token);
        $remote = $client->query(
            'query($id: ID!) { node(id: $id) { ... on ProjectV2SingleSelectField { id options { id name color description } } } }',
            ['id' => $field->github_node_id],
        )['node'] ?? null;
        if (! is_array($remote) || ($remote['id'] ?? null) !== $field->github_node_id || ! is_array($remote['options'] ?? null)) {
            throw new GitHubSyncException('GitHub did not return the current Group options. Refresh before retrying.');
        }
        $options = [];
        foreach ($remote['options'] as $option) {
            if (! is_array($option) || ! is_string($option['id'] ?? null) || ! is_string($option['name'] ?? null) || ! is_string($option['color'] ?? null)) {
                throw new GitHubSyncException('GitHub returned invalid Group options. Refresh before retrying.');
            }
            $options[] = ['id' => $option['id'], 'name' => $option['name'], 'color' => $option['color'], 'description' => $option['description'] ?? ''];
        }
        $options[] = ['name' => $name, 'color' => $color, 'description' => ''];
        $data = $client->query(
            'mutation($fieldId: ID!, $options: [ProjectV2SingleSelectFieldOptionInput!]!) { updateProjectV2Field(input: {fieldId: $fieldId, singleSelectOptions: $options}) { projectV2Field { ... on ProjectV2SingleSelectField { id options { id name color description } } } } }',
            ['fieldId' => $field->github_node_id, 'options' => $options],
        );
        $updated = $data['updateProjectV2Field']['projectV2Field'] ?? null;
        if (! is_array($updated) || ($updated['id'] ?? null) !== $field->github_node_id || ! is_array($updated['options'] ?? null)) {
            throw new GitHubSyncException('GitHub did not confirm the Group creation. Refresh before retrying.');
        }

        return DB::transaction(function () use ($field, $updated, $name): ProjectFieldOption {
            $created = null;
            foreach ($updated['options'] as $position => $option) {
                if (! is_array($option) || ! is_string($option['id'] ?? null) || ! is_string($option['name'] ?? null)) {
                    throw new GitHubSyncException('GitHub returned invalid Group options. Refresh before retrying.');
                }
                $local = ProjectFieldOption::query()->updateOrCreate(
                    ['project_field_id' => $field->id, 'github_option_id' => $option['id']],
                    ['name' => $option['name'], 'color' => $option['color'] ?? null, 'position' => $position],
                );
                if (strcasecmp($local->name, $name) === 0) {
                    $created = $local;
                }
            }
            if (! $created instanceof ProjectFieldOption) {
                throw new GitHubSyncException('GitHub did not return the new Group. Refresh before retrying.');
            }

            return $created;
        });
    }
}
