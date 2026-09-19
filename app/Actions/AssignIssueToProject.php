<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\ProjectItem;

class AssignIssueToProject
{
    public function __construct(private readonly EnqueueGitHubPush $enqueue) {}

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

        return $item;
    }
}
