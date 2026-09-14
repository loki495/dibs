<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Delivers durable push-queue rows to GitHub at its own pace, independent of the UI
 * action that created them. Local SQLite is already the confirmed result by the time
 * a row reaches here; a failure marks the row for retry or human attention, it never
 * rolls back the local write.
 *
 * Operations run in OPERATION_ORDER, not insertion order, because several depend on
 * another row having already been pushed in this same call (an issue must exist on
 * GitHub before its Project membership can, etc.). A handler whose dependency isn't
 * pushed yet returns 'waiting' and leaves the row untouched for the next drain run —
 * that is not a failure and must never touch attempts/last_error.
 */
class DrainGitHubPushQueue
{
    private const int MAX_ATTEMPTS = 3;

    /** @var list<string> */
    private const array OPERATION_ORDER = [
        'create_issue', 'create_label', 'create_group_option', 'rename_group_option',
        'add_project_membership', 'set_project_item_group', 'set_project_item_priority',
        'add_issue_labels', 'set_issue_parent',
        'update_issue_body', 'close_issue', 'delete_issue',
        'delete_project_item', 'clear_project_item_group', 'clear_project_item_priority', 'delete_group_option',
        'set_issue_labels', 'remove_issue_parent',
        'create_comment', 'update_comment',
    ];

    /** @return array{pushed: int, deferred: int, needs_attention: int, waiting: int} */
    public function handle(#[SensitiveParameter] string $token, int $limit = 20): array
    {
        $result = ['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0];

        foreach (self::OPERATION_ORDER as $operation) {
            $items = GitHubPushQueueItem::query()->whereIn('status', ['pending', 'failed'])->where('operation', $operation)->orderBy('id')->limit($limit)->get();
            foreach ($items as $item) {
                $state = match ($operation) {
                    'create_issue' => $this->pushCreateIssue($token, $item),
                    'create_label' => $this->pushCreateLabel($token, $item),
                    'create_group_option' => $this->pushCreateGroupOption($token, $item),
                    'rename_group_option' => $this->pushRenameGroupOption($token, $item),
                    'add_project_membership' => $this->pushAddProjectMembership($token, $item),
                    'set_project_item_group' => $this->pushSetProjectItemGroup($token, $item),
                    'set_project_item_priority' => $this->pushSetProjectItemPriority($token, $item),
                    'add_issue_labels' => $this->pushAddIssueLabels($token, $item),
                    'set_issue_parent' => $this->pushSetIssueParent($token, $item),
                    'update_issue_body' => $this->pushUpdateIssueBody($token, $item),
                    'close_issue' => $this->pushCloseIssue($token, $item),
                    'delete_issue' => $this->pushDeleteIssue($token, $item),
                    'delete_project_item' => $this->pushDeleteProjectItem($token, $item),
                    'clear_project_item_group' => $this->pushClearProjectItemGroup($token, $item),
                    'clear_project_item_priority' => $this->pushClearProjectItemPriority($token, $item),
                    'delete_group_option' => $this->pushDeleteGroupOption($token, $item),
                    'set_issue_labels' => $this->pushSetIssueLabels($token, $item),
                    'remove_issue_parent' => $this->pushRemoveIssueParent($token, $item),
                    'create_comment' => $this->pushCreateComment($token, $item),
                    // $operation is always one of OPERATION_ORDER's values here by construction of the outer
                    // loop, so this is really "update_comment" — a true default would be dead/unreachable
                    // and PHPStan flags it as such. Genuinely unknown operations are handled below, after the loop.
                    default => $this->pushUpdateComment($token, $item),
                };
                $result[$state] = ($result[$state] ?? 0) + 1;
            }
        }

        // Anything whose operation isn't in OPERATION_ORDER at all (a typo, a removed/renamed operation) would
        // otherwise never be fetched by the loop above and sit pending forever instead of surfacing for review.
        $unknown = GitHubPushQueueItem::query()->whereIn('status', ['pending', 'failed'])->whereNotIn('operation', self::OPERATION_ORDER)->limit($limit)->get();
        foreach ($unknown as $item) {
            $result['needs_attention']++;
            $this->rejectUnsupported($item);
        }

        return $result;
    }

    private function pushCreateIssue(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $issue = Issue::query()->find($item->target_id);
        if (! $issue instanceof Issue) {
            return $this->giveUp($item, 'Target issue no longer exists locally.');
        }
        if ($issue->github_node_id !== null) {
            // Already pushed by a prior run that failed to mark this row; reconcile instead of duplicating.
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);

            return 'pushed';
        }

        $title = (string) ($item->payload['title'] ?? $issue->title);
        $body = $item->payload['body'] ?? null;

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($repositoryId: ID!, $title: String!, $body: String) { createIssue(input: {repositoryId: $repositoryId, title: $title, body: $body}) { issue { id number title body state stateReason url updatedAt } } }',
                ['repositoryId' => $issue->repository->github_node_id, 'title' => $title, 'body' => $body],
            );
            $remote = $data['createIssue']['issue'] ?? null;
            if (! is_array($remote) || ! is_string($remote['id'] ?? null) || ! is_int($remote['number'] ?? null)) {
                throw new GitHubSyncException('GitHub did not return the created issue.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($issue, $item, $remote): void {
            $issue->update([
                'github_node_id' => $remote['id'], 'github_number' => $remote['number'], 'title' => $remote['title'],
                'body' => $remote['body'] ?? null, 'state' => $remote['state'], 'state_reason' => $remote['stateReason'] ?? null,
                'url' => $remote['url'] ?? null, 'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now(),
            ]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    /**
     * GitHub's Group-option mutation always returns the field's ENTIRE options list, not just
     * the new one. Every returned option except the one matching this row's own name is an
     * already-synced option and must be refreshed via updateOrCreate keyed by its real id, same
     * as import does. The one matching this row's name IS this row — it must be updated in place
     * (never updateOrCreate'd), or it would create a second, duplicate local option.
     */
    private function pushCreateGroupOption(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $option = ProjectFieldOption::query()->find($item->target_id);
        if (! $option instanceof ProjectFieldOption) {
            return $this->giveUp($item, 'Target Group option no longer exists locally.');
        }
        if ($option->github_option_id !== null) {
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);

            return 'pushed';
        }
        $field = ProjectField::query()->find($option->project_field_id);
        if (! $field instanceof ProjectField) {
            return $this->giveUp($item, 'The owning Project field is not available.');
        }
        $name = (string) ($item->payload['name'] ?? $option->name);
        $color = (string) ($item->payload['color'] ?? 'GRAY');

        try {
            $client = new GitHubClient($token);
            $remote = $client->query(
                'query($id: ID!) { node(id: $id) { ... on ProjectV2SingleSelectField { id options { id name color description } } } }',
                ['id' => $field->github_node_id],
            )['node'] ?? null;
            if (! is_array($remote) || ! is_array($remote['options'] ?? null)) {
                throw new GitHubSyncException('GitHub did not return the current Group options.');
            }
            $options = [];
            foreach ($remote['options'] as $existing) {
                if (! is_array($existing) || ! is_string($existing['id'] ?? null) || ! is_string($existing['name'] ?? null) || ! is_string($existing['color'] ?? null)) {
                    throw new GitHubSyncException('GitHub returned an invalid Group option.');
                }
                $options[] = ['id' => $existing['id'], 'name' => $existing['name'], 'color' => $existing['color'], 'description' => $existing['description'] ?? ''];
            }
            $options[] = ['name' => $name, 'color' => $color, 'description' => ''];
            $data = $client->query(
                'mutation($fieldId: ID!, $options: [ProjectV2SingleSelectFieldOptionInput!]!) { updateProjectV2Field(input: {fieldId: $fieldId, singleSelectOptions: $options}) { projectV2Field { ... on ProjectV2SingleSelectField { id options { id name color description } } } } }',
                ['fieldId' => $field->github_node_id, 'options' => $options],
            );
            $updated = $data['updateProjectV2Field']['projectV2Field']['options'] ?? null;
            if (! is_array($updated)) {
                throw new GitHubSyncException('GitHub did not confirm the Group creation.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        $matched = false;
        DB::transaction(function () use ($field, $updated, $name, $option, $item, &$matched): void {
            foreach ($updated as $position => $remoteOption) {
                if (! is_array($remoteOption) || ! is_string($remoteOption['id'] ?? null) || ! is_string($remoteOption['name'] ?? null)) {
                    throw new GitHubSyncException('GitHub returned an invalid Group option.');
                }
                if (! $matched && strcasecmp($remoteOption['name'], $name) === 0) {
                    $option->update(['github_option_id' => $remoteOption['id'], 'color' => $remoteOption['color'] ?? null, 'position' => $position]);
                    $matched = true;

                    continue;
                }
                ProjectFieldOption::query()->updateOrCreate(
                    ['project_field_id' => $field->id, 'github_option_id' => $remoteOption['id']],
                    ['name' => $remoteOption['name'], 'color' => $remoteOption['color'] ?? null, 'position' => $position],
                );
            }
            if ($matched) {
                $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
            }
        });

        return $matched ? 'pushed' : $this->deferOrFail($item, new GitHubSyncException('GitHub did not return the new Group option.'));
    }

    private function pushRenameGroupOption(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $option = ProjectFieldOption::query()->with('field')->find($item->target_id);
        if (! $option instanceof ProjectFieldOption) {
            return $this->giveUp($item, 'Target Group option no longer exists locally.');
        }
        if ($option->github_option_id === null) {
            return 'waiting';
        }
        $field = $option->field;
        if (! $field instanceof ProjectField) {
            return $this->giveUp($item, 'The owning Project field is not available.');
        }
        $name = (string) ($item->payload['name'] ?? $option->name);

        try {
            $client = new GitHubClient($token);
            $remote = $client->query(
                'query($id: ID!) { node(id: $id) { ... on ProjectV2SingleSelectField { id options { id name color description } } } }',
                ['id' => $field->github_node_id],
            )['node'] ?? null;
            if (! is_array($remote) || ! is_array($remote['options'] ?? null)) {
                throw new GitHubSyncException('GitHub did not return the current Group options.');
            }
            $options = [];
            $found = false;
            foreach ($remote['options'] as $existing) {
                if (! is_array($existing) || ! is_string($existing['id'] ?? null) || ! is_string($existing['name'] ?? null) || ! is_string($existing['color'] ?? null)) {
                    throw new GitHubSyncException('GitHub returned an invalid Group option.');
                }
                $isTarget = $existing['id'] === $option->github_option_id;
                $found = $found || $isTarget;
                $options[] = ['id' => $existing['id'], 'name' => $isTarget ? $name : $existing['name'], 'color' => $existing['color'], 'description' => $existing['description'] ?? ''];
            }
            if (! $found) {
                throw new GitHubSyncException('The Group option no longer exists on GitHub.');
            }
            $data = $client->query(
                'mutation($fieldId: ID!, $options: [ProjectV2SingleSelectFieldOptionInput!]!) { updateProjectV2Field(input: {fieldId: $fieldId, singleSelectOptions: $options}) { projectV2Field { ... on ProjectV2SingleSelectField { id options { id name color description } } } } }',
                ['fieldId' => $field->github_node_id, 'options' => $options],
            );
            $updated = $data['updateProjectV2Field']['projectV2Field']['options'] ?? null;
            if (! is_array($updated)) {
                throw new GitHubSyncException('GitHub did not confirm the Group rename.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($field, $updated, $item): void {
            foreach ($updated as $position => $remoteOption) {
                if (! is_array($remoteOption) || ! is_string($remoteOption['id'] ?? null) || ! is_string($remoteOption['name'] ?? null)) {
                    continue;
                }
                ProjectFieldOption::query()->updateOrCreate(
                    ['project_field_id' => $field->id, 'github_option_id' => $remoteOption['id']],
                    ['name' => $remoteOption['name'], 'color' => $remoteOption['color'] ?? null, 'position' => $position],
                );
            }
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushDeleteGroupOption(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $githubOptionId = (string) ($item->payload['github_option_id'] ?? '');
        $fieldGithubNodeId = (string) ($item->payload['field_github_node_id'] ?? '');
        if ($githubOptionId === '' || $fieldGithubNodeId === '') {
            return $this->giveUp($item, 'Missing GitHub identifiers for this Group deletion.');
        }

        try {
            $client = new GitHubClient($token);
            $remote = $client->query(
                'query($id: ID!) { node(id: $id) { ... on ProjectV2SingleSelectField { id options { id name color description } } } }',
                ['id' => $fieldGithubNodeId],
            )['node'] ?? null;
            if (! is_array($remote) || ! is_array($remote['options'] ?? null)) {
                throw new GitHubSyncException('GitHub did not return the current Group options.');
            }
            $options = [];
            foreach ($remote['options'] as $existing) {
                if (! is_array($existing) || ! is_string($existing['id'] ?? null) || ! is_string($existing['name'] ?? null) || ! is_string($existing['color'] ?? null)) {
                    throw new GitHubSyncException('GitHub returned an invalid Group option.');
                }
                if ($existing['id'] === $githubOptionId) {
                    continue;
                }
                $options[] = ['id' => $existing['id'], 'name' => $existing['name'], 'color' => $existing['color'], 'description' => $existing['description'] ?? ''];
            }
            $data = $client->query(
                'mutation($fieldId: ID!, $options: [ProjectV2SingleSelectFieldOptionInput!]!) { updateProjectV2Field(input: {fieldId: $fieldId, singleSelectOptions: $options}) { projectV2Field { ... on ProjectV2SingleSelectField { id options { id name color description } } } } }',
                ['fieldId' => $fieldGithubNodeId, 'options' => $options],
            );
            if (! is_array($data['updateProjectV2Field']['projectV2Field']['options'] ?? null)) {
                throw new GitHubSyncException('GitHub did not confirm the Group deletion.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);

        return 'pushed';
    }

    private function pushCreateLabel(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $label = Label::query()->find($item->target_id);
        if (! $label instanceof Label) {
            return $this->giveUp($item, 'Target label no longer exists locally.');
        }
        if ($label->github_node_id !== null) {
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);

            return 'pushed';
        }

        $name = (string) ($item->payload['name'] ?? $label->name);
        $color = (string) ($item->payload['color'] ?? $label->color);
        $description = $item->payload['description'] ?? null;

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($repositoryId: ID!, $name: String!, $color: String!, $description: String) { createLabel(input: {repositoryId: $repositoryId, name: $name, color: $color, description: $description}) { label { id name color description url } } }',
                ['repositoryId' => $label->repository->github_node_id, 'name' => $name, 'color' => $color, 'description' => $description],
            );
            $remote = $data['createLabel']['label'] ?? null;
            if (! is_array($remote) || ! is_string($remote['id'] ?? null)) {
                throw new GitHubSyncException('GitHub did not return the created label.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($label, $item, $remote): void {
            $label->update([
                'github_node_id' => $remote['id'], 'name' => $remote['name'] ?? $label->name,
                'color' => $remote['color'] ?? $label->color, 'description' => $remote['description'] ?? null,
                'last_synced_at' => now(), 'last_seen_at' => now(),
            ]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushAddProjectMembership(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $projectItem = ProjectItem::query()->find($item->target_id);
        if (! $projectItem instanceof ProjectItem) {
            return $this->giveUp($item, 'Target project membership no longer exists locally.');
        }
        if ($projectItem->github_node_id !== null) {
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);

            return 'pushed';
        }

        $projectItem->loadMissing('issue', 'project');
        if ($projectItem->issue === null || $projectItem->issue->github_node_id === null) {
            return 'waiting';
        }
        // GitHubProject.github_node_id is never nullable — Projects always pre-exist locally and are
        // never created through this queue, so only the relation itself needs checking here.
        if ($projectItem->project === null) {
            return $this->giveUp($item, 'The associated project is not available.');
        }

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($projectId: ID!, $contentId: ID!) { addProjectV2ItemById(input: {projectId: $projectId, contentId: $contentId}) { item { id updatedAt } } }',
                ['projectId' => $projectItem->project->github_node_id, 'contentId' => $projectItem->issue->github_node_id],
            );
            $remote = $data['addProjectV2ItemById']['item'] ?? null;
            if (! is_array($remote) || ! is_string($remote['id'] ?? null)) {
                throw new GitHubSyncException('GitHub did not return the created project membership.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($projectItem, $item, $remote): void {
            $projectItem->update([
                'github_node_id' => $remote['id'], 'content_type' => 'ISSUE',
                'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now(),
            ]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushSetProjectItemGroup(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        return $this->pushSetProjectItemFieldValue($token, $item, 'group', 'group_option_id');
    }

    private function pushSetProjectItemPriority(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        return $this->pushSetProjectItemFieldValue($token, $item, 'priority', 'priority_option_id');
    }

    private function pushSetProjectItemFieldValue(#[SensitiveParameter] string $token, GitHubPushQueueItem $item, string $semanticKey, string $payloadKey): string
    {
        $projectItem = ProjectItem::query()->find($item->target_id);
        if (! $projectItem instanceof ProjectItem) {
            return $this->giveUp($item, 'Target project membership no longer exists locally.');
        }
        if ($projectItem->github_node_id === null) {
            return 'waiting';
        }

        $projectItem->loadMissing('project');
        // GitHubProject.github_node_id is never nullable — Projects always pre-exist locally and are
        // never created through this queue, so only the relation itself needs checking here.
        if ($projectItem->project === null) {
            return $this->giveUp($item, 'The associated project is not available.');
        }

        $optionId = $item->payload[$payloadKey] ?? null;
        $option = $optionId ? ProjectFieldOption::query()->with('field')->find($optionId) : null;
        if ($optionId && ! $option instanceof ProjectFieldOption) {
            return $this->giveUp($item, "The selected $semanticKey option no longer exists locally.");
        }
        if ($option !== null && $option->github_option_id === null) {
            return 'waiting';
        }
        if (! $option instanceof ProjectFieldOption) {
            return $this->giveUp($item, "No $semanticKey option specified for this membership.");
        }

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($projectId: ID!, $itemId: ID!, $fieldId: ID!, $optionId: String!) { updateProjectV2ItemFieldValue(input: {projectId: $projectId, itemId: $itemId, fieldId: $fieldId, value: {singleSelectOptionId: $optionId}}) { projectV2Item { id updatedAt } } }',
                ['projectId' => $projectItem->project->github_node_id, 'itemId' => $projectItem->github_node_id, 'fieldId' => $option->field->github_node_id, 'optionId' => $option->github_option_id],
            );
            $remote = $data['updateProjectV2ItemFieldValue']['projectV2Item'] ?? null;
            if (! is_array($remote) || ! is_string($remote['id'] ?? null)) {
                throw new GitHubSyncException("GitHub did not confirm the $semanticKey update.");
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($projectItem, $item, $remote): void {
            $projectItem->update([
                'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now(),
            ]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushAddIssueLabels(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $issue = Issue::query()->find($item->target_id);
        if (! $issue instanceof Issue) {
            return $this->giveUp($item, 'Target issue no longer exists locally.');
        }
        if ($issue->github_node_id === null) {
            return 'waiting';
        }

        $labelIds = (array) ($item->payload['label_ids'] ?? []);
        $labels = Label::query()->whereIn('id', $labelIds)->get();
        if ($labels->count() !== count($labelIds)) {
            return $this->giveUp($item, 'One or more labels no longer exist locally.');
        }
        if ($labels->contains(fn (Label $label): bool => $label->github_node_id === null)) {
            return 'waiting';
        }

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($labelableId: ID!, $labelIds: [ID!]!) { addLabelsToLabelable(input: {labelableId: $labelableId, labelIds: $labelIds}) { labelable { ... on Issue { id } } } }',
                ['labelableId' => $issue->github_node_id, 'labelIds' => $labels->pluck('github_node_id')->all()],
            );
            $remote = $data['addLabelsToLabelable']['labelable'] ?? null;
            if (! is_array($remote) || ! is_string($remote['id'] ?? null)) {
                throw new GitHubSyncException('GitHub did not confirm the labels were added.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($issue, $item): void {
            $issue->update(['last_synced_at' => now(), 'last_seen_at' => now()]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushSetIssueParent(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $child = Issue::query()->find($item->target_id);
        if (! $child instanceof Issue) {
            return $this->giveUp($item, 'Target issue no longer exists locally.');
        }
        if ($child->github_node_id === null) {
            return 'waiting';
        }

        $parentId = $item->payload['parent_issue_id'] ?? null;
        $parent = $parentId ? Issue::query()->find($parentId) : null;
        if ($parentId && ! $parent instanceof Issue) {
            return $this->giveUp($item, 'The parent issue no longer exists locally.');
        }
        if ($parent !== null && $parent->github_node_id === null) {
            return 'waiting';
        }
        if (! $parent instanceof Issue) {
            return $this->giveUp($item, 'No parent issue specified for this issue.');
        }

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($parentId: ID!, $childId: ID!, $replaceParent: Boolean!) { addSubIssue(input: {issueId: $parentId, subIssueId: $childId, replaceParent: $replaceParent}) { subIssue { id updatedAt } } }',
                ['parentId' => $parent->github_node_id, 'childId' => $child->github_node_id, 'replaceParent' => true],
            );
            $remote = $data['addSubIssue']['subIssue'] ?? null;
            if (! is_array($remote) || ! is_string($remote['id'] ?? null)) {
                throw new GitHubSyncException('GitHub did not confirm the parent was set.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($child, $parent, $item, $remote): void {
            $child->update([
                'github_parent_node_id' => $parent->github_node_id, 'remote_updated_at' => $remote['updatedAt'] ?? null,
                'last_synced_at' => now(), 'last_seen_at' => now(),
            ]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function rejectUnsupported(GitHubPushQueueItem $item): string
    {
        $item->update(['status' => 'needs_attention', 'last_error' => 'Unsupported operation "'.$item->operation.'".', 'attempted_at' => now()]);

        return 'needs_attention';
    }

    private function giveUp(GitHubPushQueueItem $item, string $reason): string
    {
        $item->update(['status' => 'needs_attention', 'last_error' => $reason, 'attempted_at' => now()]);

        return 'needs_attention';
    }

    private function deferOrFail(GitHubPushQueueItem $item, GitHubSyncException $exception): string
    {
        $attempts = $item->attempts + 1;
        $status = $attempts >= self::MAX_ATTEMPTS ? 'needs_attention' : 'pending';
        $item->update(['attempts' => $attempts, 'last_error' => $exception->getMessage(), 'attempted_at' => now(), 'status' => $status]);

        return $status === 'needs_attention' ? 'needs_attention' : 'deferred';
    }

    private function pushUpdateIssueBody(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $issue = Issue::query()->find($item->target_id);
        if (! $issue instanceof Issue) {
            return $this->giveUp($item, 'Target issue no longer exists locally.');
        }
        if ($issue->github_node_id === null) {
            return 'waiting';
        }

        $title = (string) ($item->payload['title'] ?? $issue->title);
        $body = $item->payload['body'] ?? null;

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($issueId: ID!, $title: String!, $body: String) { updateIssue(input: {id: $issueId, title: $title, body: $body}) { issue { id title body state stateReason url updatedAt } } }',
                ['issueId' => $issue->github_node_id, 'title' => $title, 'body' => $body],
            );
            $remote = $data['updateIssue']['issue'] ?? null;
            if (! is_array($remote) || ! is_string($remote['id'] ?? null)) {
                throw new GitHubSyncException('GitHub did not return the updated issue.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($issue, $item, $remote): void {
            $issue->update([
                'title' => $remote['title'], 'body' => $remote['body'] ?? null, 'state' => $remote['state'] ?? $issue->state,
                'state_reason' => $remote['stateReason'] ?? null, 'url' => $remote['url'] ?? $issue->url,
                'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now(),
            ]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushCloseIssue(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $issue = Issue::query()->find($item->target_id);
        if (! $issue instanceof Issue) {
            return $this->giveUp($item, 'Target issue no longer exists locally.');
        }
        if ($issue->github_node_id === null) {
            return 'waiting';
        }
        if ($issue->state === 'CLOSED') {
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);

            return 'pushed';
        }

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($issueId: ID!) { closeIssue(input: {issueId: $issueId}) { issue { id state stateReason updatedAt } } }',
                ['issueId' => $issue->github_node_id],
            );
            $remote = $data['closeIssue']['issue'] ?? null;
            if (! is_array($remote) || $remote['state'] !== 'CLOSED') {
                throw new GitHubSyncException('GitHub did not confirm the issue was closed.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($issue, $item, $remote): void {
            $issue->update([
                'state' => 'CLOSED', 'state_reason' => $remote['stateReason'] ?? null,
                'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now(),
            ]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    /**
     * The local write already happened synchronously in DeleteTodoIssue (is_available is already
     * false by the time this row is ever picked up) -- this only needs to deliver the real GitHub
     * deletion. A still-null github_node_id means the issue's own create_issue row hasn't reached
     * GitHub yet (create_issue sorts earlier in OPERATION_ORDER, so within one drain pass it's
     * usually already resolved by the time this runs); waiting lets that finish first rather than
     * abandoning a task that was created and deleted before it ever synced.
     */
    private function pushDeleteIssue(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $issue = Issue::query()->find($item->target_id);
        if (! $issue instanceof Issue) {
            return $this->giveUp($item, 'Target issue no longer exists locally.');
        }
        if ($issue->github_node_id === null) {
            return 'waiting';
        }
        if ($issue->children()->where('is_available', true)->exists()) {
            return $this->giveUp($item, 'This task still has available children locally; promote or delete them before it can be deleted.');
        }

        try {
            (new GitHubClient($token))->query(
                'mutation($issueId: ID!) { deleteIssue(input: {issueId: $issueId}) { clientMutationId } }',
                ['issueId' => $issue->github_node_id],
            );
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($issue, $item): void {
            $issue->update(['last_synced_at' => now(), 'last_seen_at' => now()]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushDeleteProjectItem(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $projectItem = ProjectItem::query()->find($item->target_id);
        if (! $projectItem instanceof ProjectItem) {
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);

            return 'pushed';
        }
        if (! $projectItem->is_available) {
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);

            return 'pushed';
        }
        if ($projectItem->github_node_id === null) {
            return $this->giveUp($item, 'Target project membership has no remote identity to delete.');
        }

        $projectItem->loadMissing('project');
        // GitHubProject.github_node_id is never nullable — Projects always pre-exist locally and are
        // never created through this queue, so only the relation itself needs checking here.
        if ($projectItem->project === null) {
            return $this->giveUp($item, 'The associated project is not available.');
        }

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($projectId: ID!, $itemId: ID!) { deleteProjectV2Item(input: {projectId: $projectId, itemId: $itemId}) { deletedItemId } }',
                ['projectId' => $projectItem->project->github_node_id, 'itemId' => $projectItem->github_node_id],
            );
            if (($data['deleteProjectV2Item']['deletedItemId'] ?? null) !== $projectItem->github_node_id) {
                throw new GitHubSyncException('GitHub did not confirm the project membership was deleted.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($projectItem, $item): void {
            $projectItem->update(['is_available' => false, 'last_synced_at' => now(), 'last_seen_at' => now()]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushClearProjectItemGroup(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        return $this->pushClearProjectItemField($token, $item, 'group', 'groupOption.field', 'group_option_id');
    }

    private function pushClearProjectItemPriority(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        return $this->pushClearProjectItemField($token, $item, 'priority', 'priorityOption.field', 'priority_option_id');
    }

    private function pushClearProjectItemField(#[SensitiveParameter] string $token, GitHubPushQueueItem $item, string $semanticKey, string $relationName, string $columnName): string
    {
        $projectItem = ProjectItem::query()->find($item->target_id);
        if (! $projectItem instanceof ProjectItem) {
            return $this->giveUp($item, 'Target project membership no longer exists locally.');
        }
        if ($projectItem->{$columnName} === null) {
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);

            return 'pushed';
        }
        if ($projectItem->github_node_id === null) {
            return 'waiting';
        }

        $projectItem->loadMissing('project', $relationName);
        if ($projectItem->project === null) {
            return $this->giveUp($item, 'The associated project is not available.');
        }

        $option = $relationName === 'groupOption.field' ? $projectItem->groupOption : $projectItem->priorityOption;
        if (! $option instanceof ProjectFieldOption || $option->field === null) {
            return $this->giveUp($item, "The $semanticKey option is not available.");
        }

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($projectId: ID!, $itemId: ID!, $fieldId: ID!) { clearProjectV2ItemFieldValue(input: {projectId: $projectId, itemId: $itemId, fieldId: $fieldId}) { projectV2Item { id updatedAt } } }',
                ['projectId' => $projectItem->project->github_node_id, 'itemId' => $projectItem->github_node_id, 'fieldId' => $option->field->github_node_id],
            );
            $remote = $data['clearProjectV2ItemFieldValue']['projectV2Item'] ?? null;
            if (! is_array($remote) || ! is_string($remote['id'] ?? null)) {
                throw new GitHubSyncException("GitHub did not confirm the $semanticKey was cleared.");
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($projectItem, $item, $remote, $columnName): void {
            $projectItem->update([
                $columnName => null, 'remote_updated_at' => $remote['updatedAt'] ?? null,
                'last_synced_at' => now(), 'last_seen_at' => now(),
            ]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushSetIssueLabels(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $issue = Issue::query()->find($item->target_id);
        if (! $issue instanceof Issue) {
            return $this->giveUp($item, 'Target issue no longer exists locally.');
        }
        if ($issue->github_node_id === null) {
            return 'waiting';
        }

        $addLabelIds = (array) ($item->payload['add_label_ids'] ?? []);
        $removeLabelIds = (array) ($item->payload['remove_label_ids'] ?? []);

        $addLabels = Label::query()->whereIn('id', $addLabelIds)->get();
        if ($addLabels->count() !== count($addLabelIds)) {
            return $this->giveUp($item, 'One or more labels to add no longer exist locally.');
        }
        if ($addLabels->contains(fn (Label $label): bool => $label->github_node_id === null)) {
            return 'waiting';
        }

        $removeLabels = Label::query()->whereIn('id', $removeLabelIds)->get();
        $removeLabels = $removeLabels->filter(fn (Label $label): bool => $label->github_node_id !== null);

        if ($addLabels->isEmpty() && $removeLabels->isEmpty()) {
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);

            return 'pushed';
        }

        try {
            if ($addLabels->isNotEmpty()) {
                $data = (new GitHubClient($token))->query(
                    'mutation($labelableId: ID!, $labelIds: [ID!]!) { addLabelsToLabelable(input: {labelableId: $labelableId, labelIds: $labelIds}) { labelable { ... on Issue { id } } } }',
                    ['labelableId' => $issue->github_node_id, 'labelIds' => $addLabels->pluck('github_node_id')->all()],
                );
                $remote = $data['addLabelsToLabelable']['labelable'] ?? null;
                if (! is_array($remote) || ! is_string($remote['id'] ?? null)) {
                    throw new GitHubSyncException('GitHub did not confirm the labels were added.');
                }
            }
            if ($removeLabels->isNotEmpty()) {
                $data = (new GitHubClient($token))->query(
                    'mutation($labelableId: ID!, $labelIds: [ID!]!) { removeLabelsFromLabelable(input: {labelableId: $labelableId, labelIds: $labelIds}) { labelable { ... on Issue { id } } } }',
                    ['labelableId' => $issue->github_node_id, 'labelIds' => $removeLabels->pluck('github_node_id')->all()],
                );
                $remote = $data['removeLabelsFromLabelable']['labelable'] ?? null;
                if (! is_array($remote) || ! is_string($remote['id'] ?? null)) {
                    throw new GitHubSyncException('GitHub did not confirm the labels were removed.');
                }
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($issue, $item): void {
            $issue->update(['last_synced_at' => now(), 'last_seen_at' => now()]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushRemoveIssueParent(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $child = Issue::query()->find($item->target_id);
        if (! $child instanceof Issue) {
            return $this->giveUp($item, 'Target issue no longer exists locally.');
        }
        if ($child->github_node_id === null) {
            return $this->giveUp($item, 'Target issue has no remote identity.');
        }

        $parentGithubNodeId = (string) ($item->payload['parent_github_node_id'] ?? '');
        if ($parentGithubNodeId === '') {
            return $this->giveUp($item, 'No parent node ID specified for removal.');
        }

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($parentId: ID!, $childId: ID!) { removeSubIssue(input: {issueId: $parentId, subIssueId: $childId}) { subIssue { id updatedAt } } }',
                ['parentId' => $parentGithubNodeId, 'childId' => $child->github_node_id],
            );
            $remote = $data['removeSubIssue']['subIssue'] ?? null;
            if (! is_array($remote) || ($remote['id'] ?? null) !== $child->github_node_id) {
                throw new GitHubSyncException('GitHub did not confirm the parent was removed.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($child, $item, $remote): void {
            $child->update([
                'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now(),
            ]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushCreateComment(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $comment = Comment::query()->find($item->target_id);
        if (! $comment instanceof Comment) {
            return $this->giveUp($item, 'Target comment no longer exists locally.');
        }
        if ($comment->github_node_id !== null) {
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);

            return 'pushed';
        }

        $comment->loadMissing('issue');
        if ($comment->issue === null || $comment->issue->github_node_id === null) {
            return 'waiting';
        }

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($subjectId: ID!, $body: String!) { addComment(input: {subjectId: $subjectId, body: $body}) { commentEdge { node { id body url createdAt updatedAt author { login } } } } }',
                ['subjectId' => $comment->issue->github_node_id, 'body' => $comment->body],
            );
            $remote = $data['addComment']['commentEdge']['node'] ?? null;
            if (! is_array($remote) || ! is_string($remote['id'] ?? null) || ! is_string($remote['body'] ?? null)) {
                throw new GitHubSyncException('GitHub did not return the created comment.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($comment, $item, $remote): void {
            $comment->update([
                'github_node_id' => $remote['id'], 'body' => $remote['body'],
                'author_login' => $remote['author']['login'] ?? $comment->author_login, 'url' => $remote['url'] ?? $comment->url,
                'remote_created_at' => $remote['createdAt'] ?? null, 'remote_updated_at' => $remote['updatedAt'] ?? null,
                'last_synced_at' => now(), 'last_seen_at' => now(),
            ]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }

    private function pushUpdateComment(#[SensitiveParameter] string $token, GitHubPushQueueItem $item): string
    {
        $comment = Comment::query()->find($item->target_id);
        if (! $comment instanceof Comment) {
            return $this->giveUp($item, 'Target comment no longer exists locally.');
        }
        if ($comment->github_node_id === null) {
            return 'waiting';
        }

        try {
            $data = (new GitHubClient($token))->query(
                'mutation($commentId: ID!, $body: String!) { updateIssueComment(input: {id: $commentId, body: $body}) { issueComment { id body url createdAt updatedAt author { login } } } }',
                ['commentId' => $comment->github_node_id, 'body' => $comment->body],
            );
            $remote = $data['updateIssueComment']['issueComment'] ?? null;
            if (! is_array($remote) || ! is_string($remote['id'] ?? null)) {
                throw new GitHubSyncException('GitHub did not return the updated comment.');
            }
        } catch (GitHubSyncException $exception) {
            return $this->deferOrFail($item, $exception);
        }

        DB::transaction(function () use ($comment, $item, $remote): void {
            $comment->update([
                'body' => $remote['body'] ?? $comment->body, 'author_login' => $remote['author']['login'] ?? $comment->author_login,
                'url' => $remote['url'] ?? $comment->url, 'remote_created_at' => $remote['createdAt'] ?? $comment->remote_created_at,
                'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now(),
            ]);
            $item->update(['status' => 'pushed', 'pushed_at' => now(), 'last_error' => null]);
        });

        return 'pushed';
    }
}
