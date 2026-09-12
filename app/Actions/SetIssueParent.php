<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class SetIssueParent
{
    public function handle(#[SensitiveParameter] string $token, Issue $child, ?Issue $parent): Issue
    {
        $this->assertAvailable($child);

        if (! $parent instanceof Issue) {
            return $this->removeParent($token, $child);
        }

        $this->assertAvailable($parent);
        if ($child->repository_id !== $parent->repository_id) {
            throw new GitHubSyncException('A task can only use a parent from the same repository.');
        }
        if ($child->is($parent)) {
            throw new GitHubSyncException('A task cannot be its own parent.');
        }
        if ($child->parent_issue_id === $parent->id) {
            return $child;
        }
        $this->assertNoCycle($child, $parent);

        $data = (new GitHubClient($token))->query(
            'mutation($parentId: ID!, $childId: ID!, $replaceParent: Boolean!) { addSubIssue(input: {issueId: $parentId, subIssueId: $childId, replaceParent: $replaceParent}) { subIssue { id updatedAt } } }',
            ['parentId' => $parent->github_node_id, 'childId' => $child->github_node_id, 'replaceParent' => true],
        );
        $remote = $data['addSubIssue']['subIssue'] ?? null;
        if (! is_array($remote) || ($remote['id'] ?? null) !== $child->github_node_id) {
            throw new GitHubSyncException('GitHub did not confirm the parent assignment. Refresh before retrying.');
        }

        return DB::transaction(function () use ($child, $parent, $remote): Issue {
            $position = (int) Issue::query()->where('parent_issue_id', $parent->id)->max('sibling_position') + 1;
            $child->update(['parent_issue_id' => $parent->id, 'github_parent_node_id' => $parent->github_node_id,
                'sibling_position' => $position, 'remote_updated_at' => $remote['updatedAt'] ?? null,
                'last_synced_at' => now(), 'last_seen_at' => now()]);

            return $child->refresh();
        });
    }

    private function removeParent(#[SensitiveParameter] string $token, Issue $child): Issue
    {
        if ($child->parent_issue_id === null) {
            if ($child->github_parent_node_id !== null) {
                throw new GitHubSyncException('The current parent is not available locally. Refresh before removing it.');
            }

            return $child;
        }

        $parent = Issue::query()->where('is_available', true)->find($child->parent_issue_id);
        if (! $parent instanceof Issue) {
            throw new GitHubSyncException('The current parent is not available locally. Refresh before removing it.');
        }

        $data = (new GitHubClient($token))->query(
            'mutation($parentId: ID!, $childId: ID!) { removeSubIssue(input: {issueId: $parentId, subIssueId: $childId}) { subIssue { id updatedAt } } }',
            ['parentId' => $parent->github_node_id, 'childId' => $child->github_node_id],
        );
        $remote = $data['removeSubIssue']['subIssue'] ?? null;
        if (! is_array($remote) || ($remote['id'] ?? null) !== $child->github_node_id) {
            throw new GitHubSyncException('GitHub did not confirm removal of the parent. Refresh before retrying.');
        }

        return DB::transaction(function () use ($child, $remote): Issue {
            $child->update(['parent_issue_id' => null, 'github_parent_node_id' => null, 'sibling_position' => 0,
                'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now()]);

            return $child->refresh();
        });
    }

    private function assertAvailable(Issue $issue): void
    {
        if (! $issue->is_available || $issue->github_node_id === '') {
            throw new GitHubSyncException('The selected task is not available in the local snapshot. Refresh and try again.');
        }
    }

    private function assertNoCycle(Issue $child, Issue $parent): void
    {
        $current = $parent;
        $seen = [];
        while (true) {
            if ($current->is($child)) {
                throw new GitHubSyncException('This parent would create a hierarchy cycle.');
            }
            if (isset($seen[$current->id])) {
                throw new GitHubSyncException('The local hierarchy already contains a cycle. Refresh before editing it.');
            }
            $seen[$current->id] = true;
            if ($current->parent_issue_id === null) {
                return;
            }
            $current = Issue::query()->find($current->parent_issue_id);
            if (! $current instanceof Issue) {
                throw new GitHubSyncException('A parent in this hierarchy is not available locally. Refresh before editing it.');
            }
        }
    }
}
