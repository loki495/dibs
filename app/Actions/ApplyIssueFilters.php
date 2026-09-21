<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectFieldOption;
use App\Support\IssueFilters;
use App\Support\LabelName;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows an issue query by Project, Group, label and parent, the same way for every listing and search.
 * A name that matches nothing never fails the call; it is returned in the result so the caller can tell a
 * typo from a genuinely empty page.
 */
class ApplyIssueFilters
{
    /**
     * @param  Builder<Issue>  $query
     * @return array{areas: list<string>, groups: list<string>, labels: list<string>} the names that matched nothing
     */
    public function handle(Builder $query, IssueFilters $filters): array
    {
        $unresolved = ['areas' => [], 'groups' => [], 'labels' => []];

        $this->applyMemberships($query, $filters, $unresolved);
        $this->applyLabels($query, $filters, $unresolved);
        $this->applyParent($query, $filters);

        return $unresolved;
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  array{areas: list<string>, groups: list<string>, labels: list<string>}  $unresolved
     */
    private function applyMemberships(Builder $query, IssueFilters $filters, array &$unresolved): void
    {
        $restrictAreas = $filters->areas !== [] || $filters->areaNames !== [];
        $restrictGroups = $filters->groups !== [] || $filters->groupNames !== [];

        if (! $restrictAreas && ! $restrictGroups) {
            return;
        }

        $areaIds = $filters->areas;
        foreach ($filters->areaNames as $name) {
            $ids = GitHubProject::query()->where('is_available', true)->whereRaw('LOWER(title) = ?', [LabelName::normalize($name)])->pluck('id')->all();
            $ids === [] ? $unresolved['areas'][] = $name : $areaIds = [...$areaIds, ...$ids];
        }

        $groupIds = $filters->groups;
        foreach ($filters->groupNames as $name) {
            $ids = ProjectFieldOption::query()
                ->whereHas('field', fn (Builder $field) => $field->where('is_available', true)->where('semantic_key', 'group'))
                ->whereRaw('LOWER(name) = ?', [LabelName::normalize($name)])->pluck('id')->all();
            $ids === [] ? $unresolved['groups'][] = $name : $groupIds = [...$groupIds, ...$ids];
        }

        if (($restrictAreas && $areaIds === []) || ($restrictGroups && $groupIds === [])) {
            $query->whereRaw('1 = 0');

            return;
        }

        // One membership has to satisfy both, or an issue in Project A (group G) and Project B would match "B and G".
        $query->whereHas('projectItems', function (Builder $items) use ($restrictAreas, $areaIds, $restrictGroups, $groupIds): void {
            $items->where('is_available', true)->whereHas('project', fn (Builder $project) => $project->where('is_available', true));
            if ($restrictAreas) {
                $items->whereIn('project_id', $areaIds);
            }
            if ($restrictGroups) {
                $items->whereIn('group_option_id', $groupIds);
            }
        });
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  array{areas: list<string>, groups: list<string>, labels: list<string>}  $unresolved
     */
    private function applyLabels(Builder $query, IssueFilters $filters, array &$unresolved): void
    {
        $requested = [...$filters->labels, ...$filters->anyLabels, ...$filters->excludeLabels];

        if ($requested === []) {
            return;
        }

        $known = Label::query()->where('is_available', true)->pluck('name')->map(fn (string $name): string => LabelName::normalize($name))->all();
        $unresolved['labels'] = array_values(array_unique(array_filter($requested, fn (string $name): bool => ! in_array(LabelName::normalize($name), $known, true))));

        foreach ($filters->labels as $name) {
            $query->whereHas('labels', fn (Builder $labels) => $this->matching($labels, [LabelName::normalize($name)]));
        }

        if ($filters->anyLabels !== []) {
            $any = array_values(array_filter(array_map(LabelName::normalize(...), $filters->anyLabels), fn (string $name): bool => in_array($name, $known, true)));
            $any === []
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('labels', fn (Builder $labels) => $this->matching($labels, $any));
        }

        if ($filters->excludeLabels !== []) {
            $query->whereDoesntHave('labels', fn (Builder $labels) => $this->matching($labels, array_map(LabelName::normalize(...), $filters->excludeLabels)));
        }
    }

    /**
     * @param  Builder<Label>  $labels
     * @param  list<string>  $names  already normalized
     */
    private function matching(Builder $labels, array $names): void
    {
        $labels->where('is_available', true)->whereRaw('LOWER(name) IN ('.implode(',', array_fill(0, count($names), '?')).')', $names);
    }

    /** @param  Builder<Issue>  $query */
    private function applyParent(Builder $query, IssueFilters $filters): void
    {
        if ($filters->parentId === null) {
            return;
        }

        if (! $filters->descendants) {
            $query->where('parent_issue_id', $filters->parentId);

            return;
        }

        // UNION rather than UNION ALL so a corrupt parent cycle terminates instead of looping.
        $table = $query->getModel()->getTable();
        $query->whereRaw($table.'.id IN (WITH RECURSIVE tree(id) AS (SELECT id FROM '.$table.' WHERE parent_issue_id = ? UNION SELECT child.id FROM '.$table.' child JOIN tree ON child.parent_issue_id = tree.id) SELECT id FROM tree)', [$filters->parentId]);
    }
}
