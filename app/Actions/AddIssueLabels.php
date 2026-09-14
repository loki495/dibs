<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Models\Label;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class AddIssueLabels
{
    /** @param list<Label> $labels */
    public function handle(#[SensitiveParameter] string $token, Issue $issue, array $labels): Issue
    {
        if (! $issue->is_available || $issue->github_node_id === '') {
            throw new GitHubSyncException('The selected task is not available in the local snapshot. Refresh and try again.');
        }
        $labels = array_values(array_unique($labels, SORT_REGULAR));
        foreach ($labels as $label) {
            if (! $label->is_available || $label->repository_id !== $issue->repository_id || $label->github_node_id === '') {
                throw new GitHubSyncException('A task can only use available labels from the same repository.');
            }
        }
        if ($labels === []) {
            return $issue;
        }
        $data = (new GitHubClient($token))->query(
            'mutation($labelableId: ID!, $labelIds: [ID!]!) { addLabelsToLabelable(input: {labelableId: $labelableId, labelIds: $labelIds}) { labelable { ... on Issue { id } } } }',
            ['labelableId' => $issue->github_node_id, 'labelIds' => array_map(fn (Label $label): string => $label->github_node_id, $labels)],
        );
        if (($data['addLabelsToLabelable']['labelable']['id'] ?? null) !== $issue->github_node_id) {
            throw new GitHubSyncException('GitHub did not confirm the label assignment. Refresh before retrying.');
        }

        return DB::transaction(function () use ($issue, $labels): Issue {
            $issue->labels()->syncWithoutDetaching(array_map(fn (Label $label): int => $label->id, $labels));
            $issue->update(['last_synced_at' => now(), 'last_seen_at' => now()]);

            return $issue->load('labels');
        });
    }
}
