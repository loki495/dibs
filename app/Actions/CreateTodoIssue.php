<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Support\Facades\DB;

class CreateTodoIssue
{
    public function __construct(private readonly ResolveIdempotentWrite $idempotent) {}

    /**
     * @param  list<int>  $labelIds
     */
    public function handle(
        string $title,
        ?string $body = null,
        ?int $area = null,
        ?int $parentId = null,
        ?int $groupId = null,
        ?int $priorityId = null,
        array $labelIds = [],
        ?string $newGroupName = null,
        ?string $newLabelName = null,
        ?string $idempotencyKey = null,
    ): Issue {
        $create = function () use ($title, $body, $area, $parentId, $groupId, $priorityId, $labelIds, $newGroupName, $newLabelName): Issue {
            if ($newGroupName !== null && trim($newGroupName) !== '' && ($area === null || $area === 0)) {
                throw new TodoValidationException('Choose an area before creating a Group.');
            }
            $project = $area !== null && $area > 0 ? GitHubProject::query()->where('is_available', true)->find($area) : null;
            if ($area !== null && $area > 0 && ! $project instanceof GitHubProject) {
                throw new TodoValidationException('The selected area is no longer available. Refresh and try again.');
            }
            $parent = $parentId !== null && $parentId > 0 ? Issue::query()->where('is_available', true)->find($parentId) : null;
            if ($parentId !== null && $parentId > 0 && ! $parent instanceof Issue) {
                throw new TodoValidationException('The selected parent is no longer available. Refresh and try again.');
            }
            $group = $groupId !== null && $groupId > 0 ? ProjectFieldOption::query()->with('field')->find($groupId) : null;
            if ($groupId !== null && $groupId > 0 && (! $group instanceof ProjectFieldOption || ! $project instanceof GitHubProject || $group->field->project_id !== $project->id)) {
                throw new TodoValidationException('The selected Group is no longer available in this area. Refresh and try again.');
            }
            $priority = $priorityId !== null && $priorityId > 0 ? ProjectFieldOption::query()->with('field')->find($priorityId) : null;
            if ($priorityId !== null && $priorityId > 0 && (! $priority instanceof ProjectFieldOption || ! $project instanceof GitHubProject || $priority->field->semantic_key !== 'priority' || $priority->field->project_id !== $project->id)) {
                throw new TodoValidationException('The selected Priority is no longer available in this area. Refresh and try again.');
            }
            $labels = Label::query()->where('is_available', true)->whereIn('id', $labelIds)->get();
            if ($labels->count() !== count($labelIds) || ($parent instanceof Issue && $labels->contains(fn (Label $label): bool => $label->repository_id !== $parent->repository_id))) {
                throw new TodoValidationException('One or more selected labels are no longer available. Refresh and try again.');
            }
            $repository = GitHubRepository::query()->where('full_name', config('github.owner').'/'.config('github.repository'))->first();
            if (! $repository instanceof GitHubRepository) {
                throw new TodoValidationException('The repository is not configured or not available locally. Refresh and try again.');
            }

            return DB::transaction(function () use ($title, $body, $project, $priority, $labels, $parent, $repository, $group, $newGroupName, $newLabelName): Issue {
                if ($newGroupName !== null && trim($newGroupName) !== '') {
                    $groupField = $project->fields()->where('semantic_key', 'group')->where('is_available', true)->first();
                    $existingGroup = $groupField?->options()->whereRaw('LOWER(name) = LOWER(?)', [trim($newGroupName)])->first();
                    if ($existingGroup instanceof ProjectFieldOption) {
                        $group = $existingGroup;
                    } else {
                        $newOption = ProjectFieldOption::create([
                            'project_field_id' => $groupField->id,
                            'github_option_id' => null,
                            'name' => trim($newGroupName),
                            'color' => 'GRAY',
                            'position' => (int) $groupField->options()->max('position') + 1,
                        ]);
                        app(EnqueueGitHubPush::class)->handle('create_group_option', 'project_field_option', $newOption->id, ['name' => $newOption->name, 'color' => 'GRAY'], 'group_option:create:'.$newOption->id);
                        $group = $newOption;
                    }
                }
                if ($newLabelName !== null && trim($newLabelName) !== '') {
                    $existingLabel = Label::query()->where('repository_id', $repository->id)->whereRaw('LOWER(name) = LOWER(?)', [trim($newLabelName)])->first();
                    if ($existingLabel instanceof Label) {
                        $labels->push($existingLabel);
                    } else {
                        $newLabel = Label::create([
                            'repository_id' => $repository->id,
                            'github_node_id' => null,
                            'name' => trim($newLabelName),
                            'color' => '6B7280',
                            'is_available' => true,
                        ]);
                        app(EnqueueGitHubPush::class)->handle('create_label', 'label', $newLabel->id, ['name' => $newLabel->name, 'color' => '6B7280', 'description' => null], 'label:create:'.$newLabel->id);
                        $labels->push($newLabel);
                    }
                }
                $issue = Issue::create([
                    'repository_id' => $repository->id,
                    'github_node_id' => null,
                    'github_number' => null,
                    'title' => trim($title),
                    'body' => $body === null || $body === '' ? null : $body,
                    'state' => 'OPEN',
                    'revision' => 1,
                    'sibling_position' => 0,
                    'is_available' => true,
                    'last_seen_at' => now(),
                ]);
                app(EnqueueGitHubPush::class)->handle('create_issue', 'issue', $issue->id, ['title' => $issue->title, 'body' => $issue->body], 'issue:create:'.$issue->id);
                if ($project instanceof GitHubProject) {
                    $item = ProjectItem::create([
                        'project_id' => $project->id,
                        'issue_id' => $issue->id,
                        'github_node_id' => null,
                        'content_type' => 'ISSUE',
                        'is_available' => true,
                        'group_option_id' => $group?->id,
                        'priority_option_id' => $priority?->id,
                        'last_seen_at' => now(),
                    ]);
                    app(EnqueueGitHubPush::class)->handle('add_project_membership', 'project_item', $item->id, [], 'project_item:create:'.$item->id);
                    if ($group instanceof ProjectFieldOption) {
                        app(EnqueueGitHubPush::class)->handle('set_project_item_group', 'project_item', $item->id, ['group_option_id' => $group->id], 'project_item:group:'.$item->id);
                    }
                    if ($priority instanceof ProjectFieldOption) {
                        app(EnqueueGitHubPush::class)->handle('set_project_item_priority', 'project_item', $item->id, ['priority_option_id' => $priority->id], 'project_item:priority:'.$item->id);
                    }
                }
                if ($labels->isNotEmpty()) {
                    $issue->labels()->syncWithoutDetaching($labels->pluck('id'));
                    app(EnqueueGitHubPush::class)->handle('add_issue_labels', 'issue', $issue->id, ['label_ids' => $labels->pluck('id')->all()], 'issue:labels:'.$issue->id);
                }
                if ($parent instanceof Issue) {
                    $siblingPosition = (int) Issue::query()->where('parent_issue_id', $parent->id)->max('sibling_position') + 1;
                    $issue->update(['parent_issue_id' => $parent->id, 'sibling_position' => $siblingPosition]);
                    app(EnqueueGitHubPush::class)->handle('set_issue_parent', 'issue', $issue->id, ['parent_issue_id' => $parent->id], 'issue:parent:'.$issue->id);
                }

                return $issue;
            });
        };

        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $create();
        }

        return $this->idempotent->handle(
            $idempotencyKey,
            'issue',
            fn (int $id) => Issue::find($id),
            $create,
        );
    }
}
