<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubSyncException;
use App\Support\ProjectColor;
use Illuminate\Support\Facades\DB;

class ApplyGitHubSnapshot
{
    /** @param array<string, mixed> $snapshot */
    public function handle(array $snapshot): void
    {
        DB::transaction(function () use ($snapshot): void {
            $remote = $snapshot['repository'];
            $stamp = ['last_seen_at' => now(), 'last_synced_at' => now(), 'is_available' => true];
            $repo = GitHubRepository::query()->updateOrCreate(['github_node_id' => $remote['id']], [
                'owner' => $remote['owner']['login'], 'name' => $remote['name'], 'full_name' => $remote['nameWithOwner'],
                'url' => $remote['url'], 'is_private' => $remote['isPrivate'], 'visibility' => $remote['visibility'],
                'remote_updated_at' => $remote['updatedAt'], ...$stamp,
            ]);
            $labels = [];
            foreach ($snapshot['labels'] as $remoteLabel) {
                if (! is_string($remoteLabel['id'] ?? null) || $remoteLabel['id'] === '') {
                    // labels.github_node_id is nullable to allow a local-first pending row; a remote
                    // label must still always carry a real id, so this is enforced here rather than by the column.
                    throw new GitHubSyncException('GitHub returned a label without an id; snapshot was not applied.');
                }
                // A label already synced has this github_node_id. One created locally-first (via a
                // write tool's newLabelName, never yet pushed) has no github_node_id but the same
                // (repository_id, name) — reconcile onto that row instead of inserting a duplicate,
                // which would violate the unique (repository_id, name) constraint.
                $label = Label::query()->where('github_node_id', $remoteLabel['id'])->first()
                    ?? Label::query()->where('repository_id', $repo->id)->whereRaw('LOWER(name) = LOWER(?)', [$remoteLabel['name']])->first()
                    ?? new Label;
                $label->fill([
                    'repository_id' => $repo->id, 'github_node_id' => $remoteLabel['id'], 'name' => $remoteLabel['name'],
                    'color' => $remoteLabel['color'], 'description' => $remoteLabel['description'], ...$stamp,
                ])->save();
                $labels[$remoteLabel['id']] = $label->id;
            }
            Label::query()->where('repository_id', $repo->id)->whereNotIn('github_node_id', array_keys($labels))->update(['is_available' => false]);
            $issues = [];
            foreach ($snapshot['issues'] as $remoteIssue) {
                $issue = Issue::query()->updateOrCreate(['github_node_id' => $remoteIssue['id']], [
                    'repository_id' => $repo->id, 'github_number' => $remoteIssue['number'], 'title' => $remoteIssue['title'],
                    'body' => $remoteIssue['body'], 'state' => $remoteIssue['state'], 'state_reason' => $remoteIssue['stateReason'],
                    'url' => $remoteIssue['url'], 'github_parent_node_id' => $remoteIssue['parent']['id'] ?? null,
                    'parent_issue_id' => null, 'sibling_position' => 0, 'remote_updated_at' => $remoteIssue['updatedAt'], ...$stamp,
                ]);
                $labelIds = [];
                foreach ($remoteIssue['labels'] as $remoteLabel) {
                    if (! isset($labels[$remoteLabel['id']])) {
                        throw new GitHubSyncException('Labels changed during import; retry the snapshot.');
                    }
                    $labelIds[] = $labels[$remoteLabel['id']];
                }
                $issue->labels()->sync($labelIds);
                $issues[$remoteIssue['id']] = $issue;
                if (isset($remoteIssue['comments'])) {
                    foreach ($remoteIssue['comments'] as $comment) {
                        if (! is_string($comment['id'] ?? null) || $comment['id'] === '') {
                            // comments.github_node_id is nullable to allow a local-first pending comment;
                            // a remote comment must still always carry a real id, enforced here instead.
                            throw new GitHubSyncException('GitHub returned a comment without an id; snapshot was not applied.');
                        }
                        Comment::query()->updateOrCreate(['github_node_id' => $comment['id']], [
                            'issue_id' => $issue->id, 'body' => $comment['body'], 'author_login' => $comment['author']['login'] ?? null,
                            'url' => $comment['url'], 'remote_created_at' => $comment['createdAt'], 'remote_updated_at' => $comment['updatedAt'], ...$stamp,
                        ]);
                    }
                    $issue->comments()->whereNotIn('github_node_id', array_column($remoteIssue['comments'], 'id'))->update(['is_available' => false]);
                }
            }
            foreach ($snapshot['issues'] as $remoteIssue) {
                $issue = $issues[$remoteIssue['id']];
                $parent = $remoteIssue['parent']['id'] ?? null;
                $issue->update(['parent_issue_id' => $issues[$parent]->id ?? null]);
                foreach ($remoteIssue['children'] as $position => $child) {
                    if (isset($issues[$child['id']]) && $issues[$child['id']]->github_parent_node_id === $issue->github_node_id) {
                        $issues[$child['id']]->update(['sibling_position' => $position]);
                    }
                }
            }
            Issue::query()->where('repository_id', $repo->id)->whereNotIn('github_node_id', array_keys($issues))->update(['is_available' => false]);
            foreach ($snapshot['projects'] as $project) {
                $this->project($project, $issues, $stamp);
            }
        });
    }

    /** @param array<string, mixed> $remote
     * @param  array<string, Issue>  $issues
     * @param  array<string, mixed>  $stamp
     */
    private function project(array $remote, array $issues, array $stamp): void
    {
        $project = GitHubProject::query()->firstOrNew(['github_node_id' => $remote['id']]);
        if (! $project->exists || ! ctype_xdigit((string) $project->color) || strlen((string) $project->color) !== 6) {
            $project->color = ProjectColor::default($remote['id']);
        }
        $project->fill([
            'owner' => $remote['owner'], 'github_number' => $remote['number'], 'title' => $remote['title'], 'url' => $remote['url'],
            'is_closed' => $remote['closed'], 'is_public' => $remote['public'], 'remote_updated_at' => $remote['updatedAt'], ...$stamp,
        ])->save();
        $fields = [];
        $options = [];
        foreach ($remote['fields'] as $remoteField) {
            $field = ProjectField::query()->firstOrNew(['github_node_id' => $remoteField['id']]);
            if (! $field->exists) {
                $key = strtolower($remoteField['name']);
                $field->semantic_key = in_array($key, ['status', 'group', 'priority', 'planned', 'due', 'repeat'], true) ? $key : null;
            }
            $field->fill(['project_id' => $project->id, 'name' => $remoteField['name'], 'data_type' => $remoteField['dataType'], 'configuration_json' => $remoteField, ...$stamp])->save();
            $fields[$remoteField['id']] = $field;
            foreach ($remoteField['options'] ?? [] as $position => $remoteOption) {
                if (! is_string($remoteOption['id'] ?? null) || $remoteOption['id'] === '') {
                    // project_field_options.github_option_id is nullable to allow a local-first pending Group
                    // option; a remote option must still always carry a real id, enforced here instead.
                    throw new GitHubSyncException('GitHub returned a Project field option without an id; snapshot was not applied.');
                }
                $option = ProjectFieldOption::query()->updateOrCreate(['project_field_id' => $field->id, 'github_option_id' => $remoteOption['id']], [
                    'name' => $remoteOption['name'], 'color' => $remoteOption['color'], 'position' => $position,
                ]);
                $options[$remoteField['id']][$remoteOption['id']] = $option->id;
            }
        }
        $project->fields()->whereNotIn('github_node_id', array_keys($fields))->update(['is_available' => false]);
        foreach ($remote['items'] as $item) {
            $values = ['planned_on' => null, 'due_on' => null, 'repeat_rule' => null, 'status_option_id' => null, 'group_option_id' => null, 'priority_option_id' => null];
            foreach ($item['values'] as $value) {
                $fieldId = $value['field']['id'] ?? '';
                $semantic = $fields[$fieldId]->semantic_key ?? null;
                if (in_array($semantic, ['planned', 'due'], true)) {
                    $values[$semantic.'_on'] = $value['date'] ?? null;
                } elseif ($semantic === 'repeat') {
                    $values['repeat_rule'] = $value['text'] ?? null;
                } elseif (in_array($semantic, ['status', 'group', 'priority'], true)) {
                    $values[$semantic.'_option_id'] = $options[$fieldId][$value['optionId'] ?? ''] ?? null;
                }
            }
            if (! is_string($item['id'] ?? null) || $item['id'] === '') {
                // project_items.github_node_id is nullable to allow a local-first pending row; a
                // remote item must still always carry a real id, so this is enforced here rather than by the column.
                throw new GitHubSyncException('GitHub returned a Project item without an id; snapshot was not applied.');
            }
            $contentId = $item['content']['id'] ?? '';
            $issueId = $issues[$contentId]->id ?? null;
            $identity = $issueId === null ? ['github_node_id' => $item['id']] : ['project_id' => $project->id, 'issue_id' => $issueId];
            ProjectItem::query()->updateOrCreate($identity, [
                'github_node_id' => $item['id'],
                'project_id' => $project->id, 'issue_id' => $issues[$contentId]->id ?? null, 'content_type' => $item['type'],
                'archived_at' => $item['isArchived'] ? $item['updatedAt'] : null,
                'raw_fields_json' => ['values' => $item['values'], 'content' => $item['content']],
                'remote_updated_at' => $item['updatedAt'], ...$values, ...$stamp,
            ]);
        }
        $project->items()->whereNotIn('github_node_id', array_column($remote['items'], 'id'))->update(['is_available' => false]);
    }
}
