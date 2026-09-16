<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Support\IssueSummary;
use Illuminate\Validation\ValidationException;

class SearchTodoIssues
{
    public const MAX_QUERY_LENGTH = 200;

    public const EXCERPT_LENGTH = 240;

    private const int EXCERPT_CONTEXT = 60;

    /** @return array<string, mixed> */
    public function handle(
        string $query,
        ?int $area = null,
        ?int $group = null,
        ?string $label = null,
        string $state = 'ALL',
        int $page = 1,
        int $perPage = ListTodoIssues::DEFAULT_PER_PAGE,
    ): array {
        $terms = array_values(array_unique(preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if ($terms === [] || mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw ValidationException::withMessages(['query' => 'Provide a non-empty search query of at most '.self::MAX_QUERY_LENGTH.' characters.']);
        }

        $issues = Issue::query()->where('is_available', true)
            ->when($state !== 'ALL', fn ($builder) => $builder->where('state', $state))
            ->when($area !== null || $group !== null, fn ($builder) => $builder->whereHas('projectItems', fn ($items) => $items
                ->where('is_available', true)
                ->whereHas('project', fn ($project) => $project->where('is_available', true))
                ->when($area !== null, fn ($items) => $items->where('project_id', $area))
                ->when($group !== null, fn ($items) => $items->where('group_option_id', $group))))
            ->when($label !== null, fn ($builder) => $builder->whereHas('labels', fn ($labels) => $labels
                ->where('is_available', true)->where('name', $label)))
            ->withCount(['children' => fn ($children) => $children->where('is_available', true)])
            ->with([
                'labels' => fn ($labels) => $labels->where('is_available', true),
                'projectItems' => fn ($items) => $items->where('is_available', true)
                    ->whereHas('project', fn ($project) => $project->where('is_available', true)),
                'projectItems.project', 'projectItems.groupOption', 'projectItems.priorityOption',
            ]);

        $ranking = [];
        $patterns = [];
        foreach ($terms as $term) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $issues->where(fn ($matches) => $matches
                ->whereRaw("title LIKE ? ESCAPE '\\'", [$pattern])
                ->orWhereRaw("body LIKE ? ESCAPE '\\'", [$pattern]));
            $ranking[] = "CASE WHEN title LIKE ? ESCAPE '\\' THEN 1 ELSE 0 END";
            $patterns[] = $pattern;
        }

        $paginator = $issues->orderByRaw('('.implode(' + ', $ranking).') DESC', $patterns)
            ->orderBy('id')
            ->paginate(max(1, min($perPage, ListTodoIssues::MAX_PER_PAGE)), ['*'], 'page', max(1, $page));

        return [
            'items' => $paginator->getCollection()->map(fn (Issue $issue): array => [
                ...IssueSummary::from($issue),
                'excerpt' => $this->excerpt($issue->body ?? '', $terms),
            ])->all(),
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
        ];
    }

    /** @param list<string> $terms */
    private function excerpt(string $body, array $terms): string
    {
        $positions = [];
        foreach ($terms as $term) {
            $position = mb_stripos($body, $term);
            if ($position !== false) {
                $positions[] = $position;
            }
        }

        $start = $positions === [] ? 0 : max(0, min($positions) - self::EXCERPT_CONTEXT);
        $text = mb_substr($body, $start, self::EXCERPT_LENGTH);

        return ($start > 0 ? '…' : '').$text.($start + self::EXCERPT_LENGTH < mb_strlen($body) ? '…' : '');
    }
}
