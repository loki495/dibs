<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Support\IssueFilters;
use App\Support\IssueSummary;
use App\Support\KnowledgeLabels;

class ListTodoIssues
{
    public function __construct(private readonly ApplyIssueFilters $applyFilters) {}

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
        ?IssueFilters $filters = null,
    ): array {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $page = max(1, $page);

        $query = Issue::query()->where('is_available', true)
            ->when($state !== 'ALL', fn ($query) => $query->where('state', $state))
            ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches
                ->where('title', 'like', '%'.$search.'%')
                ->orWhere('github_number', $search)))
            ->when($view === 'knowledge', fn ($query) => $query->whereHas('labels', fn ($labels) => $labels->whereIn('name', KnowledgeLabels::NAMES)))
            ->when($view === 'tasks', fn ($query) => $query->whereDoesntHave('labels', fn ($labels) => $labels->whereIn('name', KnowledgeLabels::NAMES)));
        $unresolved = array_filter($this->applyFilters->handle($query, ($filters ?? new IssueFilters)->withLegacy($area, $group, $label, $parentId)));

        $paginator = $query
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
            'items' => $paginator->getCollection()->map(IssueSummary::from(...))->all(),
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
            ...($unresolved === [] ? [] : ['unresolved' => $unresolved]),
        ];
    }
}
