<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubProject;
use App\Models\Issue;
use App\Support\KnowledgeLabels;

class ListTodoIssues
{
    public const MAX_PER_PAGE = 50;

    public const DEFAULT_PER_PAGE = 15;

    /** @return array<string, mixed> */
    public function handle(
        ?int $area = null,
        ?int $group = null,
        ?string $label = null,
        string $view = 'tasks',
        string $state = 'OPEN',
        string $search = '',
        ?int $parentId = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): array {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $page = max(1, $page);

        $paginator = Issue::query()->where('is_available', true)
            ->when($state !== 'ALL', fn ($query) => $query->where('state', $state))
            ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches
                ->where('title', 'like', '%'.$search.'%')
                ->orWhere('github_number', $search)))
            ->when($area !== null, fn ($query) => $query->whereHas('projectItems', fn ($items) => $items
                ->where('is_available', true)->where('project_id', $area)))
            ->when($group !== null, fn ($query) => $query->whereHas('projectItems', fn ($items) => $items
                ->where('is_available', true)->where('group_option_id', $group)))
            ->when($label !== null, fn ($query) => $query->whereHas('labels', fn ($labels) => $labels->where('name', $label)))
            ->when($parentId !== null, fn ($query) => $query->where('parent_issue_id', $parentId))
            ->when($view === 'knowledge', fn ($query) => $query->whereHas('labels', fn ($labels) => $labels->whereIn('name', KnowledgeLabels::NAMES)))
            ->when($view === 'tasks', fn ($query) => $query->whereDoesntHave('labels', fn ($labels) => $labels->whereIn('name', KnowledgeLabels::NAMES)))
            ->withCount(['children' => fn ($query) => $query->where('is_available', true)])
            ->with([
                'labels' => fn ($query) => $query->where('is_available', true),
                'projectItems' => fn ($query) => $query->where('is_available', true)
                    ->whereHas('project', fn ($project) => $project->where('is_available', true)),
                'projectItems.project', 'projectItems.groupOption', 'projectItems.priorityOption',
            ])
            ->orderBy('github_number')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $paginator->getCollection()->map($this->summarize(...))->all(),
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
        ];
    }

    /** @return array<string, mixed> */
    private function summarize(Issue $issue): array
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
        ];
    }
}
