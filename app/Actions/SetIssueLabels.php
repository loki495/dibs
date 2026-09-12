<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Models\Label;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class SetIssueLabels
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
        $current = $issue->labels()->where('is_available', true)->get();
        $requestedIds = collect($labels)->pluck('id')->all();
        $added = collect($labels)->reject(fn (Label $label): bool => $current->contains('id', $label->id))->values();
        $removed = $current->reject(fn (Label $label): bool => in_array($label->id, $requestedIds, true))->values();
        if ($added->isEmpty() && $removed->isEmpty()) {
            return $issue->load('labels');
        }

        $client = new GitHubClient($token);
        $this->add($client, $issue, $added);
        $this->remove($client, $issue, $removed);

        return DB::transaction(function () use ($issue, $labels): Issue {
            $issue->labels()->sync(array_map(fn (Label $label): int => $label->id, $labels));
            $issue->update(['last_synced_at' => now(), 'last_seen_at' => now()]);

            return $issue->load('labels');
        });
    }

    /** @param Collection<int, Label> $labels */
    private function add(GitHubClient $client, Issue $issue, Collection $labels): void
    {
        if ($labels->isEmpty()) {
            return;
        }
        $data = $client->query(
            'mutation($labelableId: ID!, $labelIds: [ID!]!) { addLabelsToLabelable(input: {labelableId: $labelableId, labelIds: $labelIds}) { labelable { id } } }',
            ['labelableId' => $issue->github_node_id, 'labelIds' => $labels->pluck('github_node_id')->all()],
        );
        if (($data['addLabelsToLabelable']['labelable']['id'] ?? null) !== $issue->github_node_id) {
            throw new GitHubSyncException('GitHub did not confirm the label addition. Refresh before retrying.');
        }
    }

    /** @param Collection<int, Label> $labels */
    private function remove(GitHubClient $client, Issue $issue, Collection $labels): void
    {
        if ($labels->isEmpty()) {
            return;
        }
        $data = $client->query(
            'mutation($labelableId: ID!, $labelIds: [ID!]!) { removeLabelsFromLabelable(input: {labelableId: $labelableId, labelIds: $labelIds}) { labelable { id } } }',
            ['labelableId' => $issue->github_node_id, 'labelIds' => $labels->pluck('github_node_id')->all()],
        );
        if (($data['removeLabelsFromLabelable']['labelable']['id'] ?? null) !== $issue->github_node_id) {
            throw new GitHubSyncException('GitHub did not confirm the label removal. Refresh before retrying.');
        }
    }
}
