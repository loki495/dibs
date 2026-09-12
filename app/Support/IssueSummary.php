<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GitHubProject;
use App\Models\Issue;

class IssueSummary
{
    /**
     * Shape one issue into a bounded summary. The caller must have eager-loaded
     * `labels`, `projectItems.project`, `projectItems.groupOption`,
     * `projectItems.priorityOption`, and a `children_count` withCount.
     *
     * @return array<string, mixed>
     */
    public static function from(Issue $issue): array
    {
        $names = $issue->labels->pluck('name')->all();
        $membership = $issue->projectItems->first();

        return [
            'id' => $issue->id,
            'number' => $issue->github_number,
            'title' => $issue->title,
            'state' => $issue->state,
            'url' => $issue->url,
            'container' => $issue->children_count > 0,
            'knowledge' => count(array_intersect($names, KnowledgeLabels::NAMES)) > 0,
            'labels' => $names,
            'parentId' => $issue->parent_issue_id,
            'area' => $membership?->project instanceof GitHubProject ? ['id' => $membership->project->id, 'title' => $membership->project->title] : null,
            'group' => $membership?->groupOption?->name,
            'priority' => $membership?->priorityOption?->name,
            'revision' => $issue->revision,
        ];
    }
}
