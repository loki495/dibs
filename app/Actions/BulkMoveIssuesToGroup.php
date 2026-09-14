<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Support\Facades\DB;

/**
 * Bulk equivalent of the single-task "Area + Group" edit: moves every selected task's Project
 * membership to the given area (creating one if it didn't have it yet, and dropping any other
 * area's membership, matching the one-project-per-task invariant the single-task edit form
 * already enforces) and sets its Group within that area.
 */
class BulkMoveIssuesToGroup
{
    /**
     * @param  list<int>  $issueIds
     * @return int number of tasks actually changed
     */
    public function handle(array $issueIds, int $projectId, ?int $groupId): int
    {
        $project = GitHubProject::query()->where('is_available', true)->find($projectId);
        if (! $project instanceof GitHubProject) {
            throw new TodoValidationException('The selected area is no longer available. Refresh and try again.');
        }
        $group = $groupId !== null ? ProjectFieldOption::query()->with('field')->find($groupId) : null;
        if ($groupId !== null && (! $group instanceof ProjectFieldOption || $group->field?->project_id !== $project->id)) {
            throw new TodoValidationException('The selected Group is not available in this area. Refresh and try again.');
        }
        $issues = Issue::query()->where('is_available', true)->whereIn('id', $issueIds)
            ->with(['projectItems' => fn ($query) => $query->where('is_available', true)->whereNull('archived_at')])->get();

        return DB::transaction(function () use ($issues, $project, $group): int {
            $moved = 0;
            foreach ($issues as $issue) {
                $item = $issue->projectItems->firstWhere('project_id', $project->id);
                if (! $item instanceof ProjectItem) {
                    $item = ProjectItem::create([
                        'project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => null,
                        'content_type' => 'ISSUE', 'is_available' => true, 'last_seen_at' => now(),
                    ]);
                    app(EnqueueGitHubPush::class)->handle('add_project_membership', 'project_item', $item->id, [], 'project_item:create:'.$item->id);
                }
                foreach ($issue->projectItems->where('project_id', '!==', $project->id) as $obsolete) {
                    if ($obsolete->github_node_id !== null) {
                        app(EnqueueGitHubPush::class)->handle('delete_project_item', 'project_item', $obsolete->id, [], 'project_item:delete:'.$obsolete->id);
                    } else {
                        $obsolete->update(['is_available' => false]);
                    }
                }
                if ($item->group_option_id !== $group?->id) {
                    $item->update(['group_option_id' => $group?->id]);
                    if ($group instanceof ProjectFieldOption) {
                        app(EnqueueGitHubPush::class)->handle('set_project_item_group', 'project_item', $item->id, ['group_option_id' => $group->id], 'project_item:group:'.$item->id.':'.now()->timestamp);
                    } else {
                        app(EnqueueGitHubPush::class)->handle('clear_project_item_group', 'project_item', $item->id, [], 'project_item:clear_group:'.$item->id.':'.now()->timestamp);
                    }
                    $moved++;
                }
            }

            return $moved;
        });
    }
}
