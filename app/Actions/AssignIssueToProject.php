<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\ProjectItem;
use App\Services\Activity\ActivityRecorder;

class AssignIssueToProject
{
    public function __construct(
        private readonly EnqueueGitHubPush $enqueue,
        private readonly ActivityRecorder $recorder,
    ) {}

    /**
     * Make the given project the issue's only area: reuse or create its membership there and leave every
     * other one (pass null to leave all). A membership GitHub already knows is queued for deletion; one that
     * was never pushed is simply retired locally. Returns the membership in the chosen project, if any.
     */
    public function handle(Issue $issue, ?GitHubProject $project): ?ProjectItem
    {
        $memberships = ProjectItem::query()->where('issue_id', $issue->id)->where('is_available', true)->whereNull('archived_at')->get();

        $item = null;
        if ($project instanceof GitHubProject) {
            $item = $memberships->firstWhere('project_id', $project->id);
            if (! $item instanceof ProjectItem) {
                $item = ProjectItem::create([
                    'project_id' => $project->id,
                    'issue_id' => $issue->id,
                    'github_node_id' => null,
                    'content_type' => 'ISSUE',
                    'is_available' => true,
                    'last_seen_at' => now(),
                ]);
                $this->enqueue->handle('add_project_membership', 'project_item', $item->id, [], 'project_item:create:'.$item->id);
            }
        }

        foreach ($memberships->where('project_id', '!=', $project?->id) as $obsolete) {
            if ($obsolete->github_node_id !== null) {
                $this->enqueue->handle('delete_project_item', 'project_item', $obsolete->id, [], 'project_item:delete:'.$obsolete->id);
            } else {
                $obsolete->update(['is_available' => false]);
            }
        }

        $this->recordAreaChange($issue, $memberships->pluck('project_id')->all(), $project);

        return $item;
    }

    /** @param  list<int>  $previousProjectIds */
    private function recordAreaChange(Issue $issue, array $previousProjectIds, ?GitHubProject $project): void
    {
        $before = $previousProjectIds === []
            ? null
            : implode(', ', GitHubProject::query()->whereIn('id', $previousProjectIds)->orderBy('title')->pluck('title')->all());
        $after = $project?->title;

        if ($before !== $after) {
            $this->recorder->change('AssignIssueToProject', $issue, 'Changed area', ['area' => ['from' => $before, 'to' => $after]]);
        }
    }
}
