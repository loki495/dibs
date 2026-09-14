<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\SyncState;
use App\Support\ProjectColor;
use Illuminate\Support\Str;

class BuildIssueTree
{
    private const array TREE_SORTS = ['project', 'group'];

    /**
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    public function handle(int $area = 0, string $view = 'tasks', string $search = '', string $state = 'OPEN', int $group = 0, array $labels = [], int $priority = 0, string $sortBy = 'project'): array
    {
        $issues = Issue::query()->where('is_available', true)->with([
            'labels' => fn ($query) => $query->where('is_available', true),
            'projectItems' => fn ($query) => $query->where('is_available', true)->whereNull('archived_at')->whereHas('project', fn ($project) => $project->where('is_available', true)),
            'projectItems.project', 'projectItems.groupOption', 'projectItems.priorityOption', 'projectItems.statusOption',
        ])->orderBy('sibling_position')->orderBy('github_number')->get();
        $containerIds = $issues->pluck('parent_issue_id')->filter()->flip()->all();
        $requiresParent = in_array('parent', $labels, true);
        $requestedLabels = array_values(array_filter($labels, fn (string $label): bool => $label !== 'parent'));
        $today = now(config('dibs.timezone'))->toDateString();
        $nodes = [];
        $groups = [];
        $areaCounts = [];
        $matches = [];
        $taskCount = 0;
        $dailyCount = 0;
        foreach ($issues as $issue) {
            $names = $issue->labels->pluck('name')->all();
            $container = isset($containerIds[$issue->id]);
            $knowledge = count(array_intersect($names, ['research', 'lesson', 'decision', 'guide'])) > 0;
            $actionable = ! $container && ! $knowledge;
            $memberships = [];
            foreach ($issue->projectItems as $item) {
                $memberships[] = ['area' => $item->project_id, 'title' => $item->project->title,
                    'groupId' => $item->group_option_id, 'group' => $item->groupOption?->name, 'priority' => $item->priorityOption?->name, 'status' => $item->statusOption?->name, 'color' => app(ProjectColor::class)->for($item->project),
                    'planned' => $item->planned_on?->toDateString(), 'due' => $item->due_on?->toDateString()];
                if ($item->groupOption && ($area === 0 || $item->project_id === $area)) {
                    $groups[$item->groupOption->id] = $item->groupOption->name;
                }
                if ($actionable && $issue->state === 'OPEN') {
                    $areaCounts[$item->project_id] = ($areaCounts[$item->project_id] ?? 0) + 1;
                }
            }
            $inArea = $area === 0 || collect($memberships)->contains('area', $area);
            $inGroup = $group === 0 || collect($memberships)->contains(fn (array $item): bool => $item['groupId'] === $group && ($area === 0 || $item['area'] === $area));
            $due = collect($memberships)->contains(fn (array $item): bool => ($item['planned'] !== null && $item['planned'] <= $today) || ($item['due'] !== null && $item['due'] <= $today));
            $daily = $actionable && $issue->state === 'OPEN' && ($due || in_array('today', $names, true));
            $taskCount += (int) ($actionable && $issue->state === 'OPEN');
            $dailyCount += (int) $daily;
            $modeMatches = match ($view) {
                'knowledge' => $knowledge,
                'daily' => $daily,
                default => ! $knowledge,
            };
            $stateMatches = $view === 'daily' || $state === 'ALL' || $issue->state === $state;
            $searchMatches = $search === '' || Str::contains(Str::lower($issue->title.' '.$issue->body.' #'.$issue->github_number), Str::lower(trim($search)));
            $labelMatches = ($requiresParent === false || $container) && ($requestedLabels === [] || count(array_intersect($requestedLabels, $names)) > 0);
            $priorityMatches = $priority === 0 || collect($memberships)->contains(fn (array $item): bool => $item['priority'] === (string) $priority && ($area === 0 || $item['area'] === $area));
            $matches[$issue->id] = $inArea && $inGroup && $modeMatches && $stateMatches && $searchMatches && $labelMatches && $priorityMatches;
            $nodes[$issue->id] = ['id' => $issue->id, 'number' => $issue->github_number, 'title' => $issue->title, 'state' => $issue->state,
                'parent' => $issue->parent_issue_id, 'remoteParent' => $issue->github_parent_node_id,
                'container' => $container, 'knowledge' => $knowledge, 'memberships' => $memberships, 'projectTitle' => $memberships[0]['title'] ?? null, 'projectColor' => $memberships[0]['color'] ?? null, 'projectAreaId' => $memberships[0]['area'] ?? null, 'labels' => $names, 'labelData' => $issue->labels->map(fn (Label $label): array => ['name' => $label->name, 'color' => ctype_xdigit((string) $label->color) && strlen((string) $label->color) === 6 ? '#'.$label->color : null])->all(),
                'context' => ! $matches[$issue->id], 'outsideArea' => ! $inArea];
        }
        $included = [];
        foreach ($matches as $id => $match) {
            if (! $match) {
                continue;
            }
            $chain = [];
            while ($id !== null && isset($nodes[$id]) && ! isset($chain[$id])) {
                $included[$id] = true;
                $chain[$id] = true;
                $id = $nodes[$id]['parent'];
            }
        }
        $children = [];
        foreach ($nodes as $id => $node) {
            if (isset($included[$id])) {
                $parent = isset($included[$node['parent']]) ? $node['parent'] : 0;
                $children[$parent][] = $id;
            }
        }

        $isTree = in_array($sortBy, self::TREE_SORTS, true);
        $rows = $isTree
            ? $this->buildTreeRows($nodes, $children, array_keys($included), $sortBy)
            : $this->buildFlatRows($nodes, $matches, $sortBy, $area);

        if ($isTree && $group === 0 && $view !== 'daily') {
            $rows = $this->insertGroupRoots($rows, $area);
            if ($sortBy === 'project') {
                $rows = $this->insertProjectRoots($rows, $area);
            }
        }
        asort($groups);
        $labelOptions = Label::query()->where('is_available', true)->orderBy('name')->pluck('name', 'name')->all();
        $labelOptions['parent'] = 'parent';
        asort($labelOptions);

        return ['rows' => $rows, 'projects' => GitHubProject::query()->where('is_available', true)->where('is_closed', false)->orderBy('github_number')->get(),
            'areaCounts' => $areaCounts, 'taskCount' => $taskCount, 'dailyCount' => $dailyCount,
            'matchCount' => count(array_filter($matches)), 'groups' => $groups, 'labelOptions' => $labelOptions,
            'filtered' => $search !== '' || $group !== 0 || $labels !== [] || $priority !== 0 || $view !== 'tasks', 'today' => $today,
            'sync' => SyncState::query()->where('resource_key', 'github:'.config('github.owner').'/'.config('github.repository'))->first()];
    }

    /** @param array<int, array<string, mixed>> $nodes
     * @param  array<int, list<int>>  $children
     * @param  list<int>  $includedIds
     * @return list<array<string, mixed>>
     */
    private function buildTreeRows(array $nodes, array $children, array $includedIds, string $sortBy): array
    {
        if ($sortBy === 'group') {
            foreach ($children as &$siblings) {
                $siblings = array_reverse($siblings);
            }
            unset($siblings);
        } elseif (isset($children[0])) {
            $children[0] = $this->clusterRootsByProject($children[0], $nodes);
        }

        $rows = [];
        $visited = [];
        $walk = function (int $id, int $treeRootId, array $ancestors = []) use (&$walk, &$rows, &$visited, $nodes, $children): void {
            if (isset($visited[$id])) {
                return;
            }
            $visited[$id] = true;
            $node = $nodes[$id];
            $rows[] = [...$node, 'ancestors' => $ancestors, 'depth' => count($ancestors), 'hasChildren' => count($children[$id] ?? []) > 0,
                'unresolvedParent' => $node['remoteParent'] !== null && ! isset($nodes[$node['parent']]), 'virtual' => false, 'parentTitle' => null, 'treeRootId' => $treeRootId];
            foreach ($children[$id] ?? [] as $child) {
                $walk($child, $treeRootId, [...$ancestors, $id]);
            }
        };
        foreach ($children[0] ?? [] as $id) {
            $walk($id, $id);
        }
        // Cycle members never resolve into $children[0] (each thinks the other is its parent), so walk
        // every included id too -- $visited already skips anything the primary pass rendered.
        foreach ($includedIds as $id) {
            $walk($id, $id);
        }

        return $rows;
    }

    /** @param list<int> $rootIds
     * @param  array<int, array<string, mixed>>  $nodes
     * @return list<int>
     */
    private function clusterRootsByProject(array $rootIds, array $nodes): array
    {
        $buckets = [];
        foreach ($rootIds as $id) {
            $buckets[$nodes[$id]['projectAreaId'] ?? 0][] = $id;
        }

        $ordered = [];
        foreach (GitHubProject::query()->where('is_available', true)->orderBy('github_number')->pluck('id') as $projectId) {
            if (isset($buckets[$projectId])) {
                array_push($ordered, ...$buckets[$projectId]);
                unset($buckets[$projectId]);
            }
        }
        foreach ($buckets as $remaining) {
            array_push($ordered, ...$remaining);
        }

        return $ordered;
    }

    /** @param array<int, array<string, mixed>> $nodes
     * @param  array<int, bool>  $matches
     * @return list<array<string, mixed>>
     */
    private function buildFlatRows(array $nodes, array $matches, string $sortBy, int $area): array
    {
        $ids = array_keys(array_filter($matches));
        $sortKey = fn (int $id): int => match ($sortBy) {
            'newest_first' => -$nodes[$id]['number'],
            'newest_last' => $nodes[$id]['number'],
            default => $this->minPriority($nodes[$id], $area),
        };
        usort($ids, fn (int $left, int $right): int => $sortKey($left) <=> $sortKey($right) ?: $nodes[$left]['number'] <=> $nodes[$right]['number']);

        return array_map(function (int $id) use ($nodes): array {
            $node = $nodes[$id];
            $parentTitle = $node['parent'] !== null && isset($nodes[$node['parent']]) ? $nodes[$node['parent']]['title'] : null;

            return [...$node, 'ancestors' => [], 'depth' => 0, 'hasChildren' => false, 'unresolvedParent' => false, 'virtual' => false, 'parentTitle' => $parentTitle, 'treeRootId' => $id];
        }, $ids);
    }

    /** @param array{memberships: list<array<string, mixed>>} $node */
    private function minPriority(array $node, int $area): int
    {
        return collect($node['memberships'])->filter(fn (array $membership): bool => $area === 0 || $membership['area'] === $area)
            ->pluck('priority')->filter(fn (?string $value): bool => ctype_digit((string) $value))->map(fn (string $value): int => (int) $value)
            ->min() ?? 999;
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function insertGroupRoots(array $rows, int $area): array
    {
        $rootGroups = [];
        foreach ($rows as $row) {
            if ($row['id'] !== $row['treeRootId']) {
                continue;
            }
            foreach ($row['memberships'] as $membership) {
                if (($area === 0 || $membership['area'] === $area) && $membership['groupId'] !== null && $membership['group'] !== null) {
                    $rootGroups[$row['id']] = ['id' => 'group-'.$membership['groupId'], 'title' => $membership['group'], 'projectTitle' => $membership['title'], 'color' => $membership['color'], 'projectAreaId' => $membership['area']];
                    break;
                }
            }
        }

        $segments = $this->segmentByTreeRoot($rows);

        // Collect every segment belonging to each group up front, regardless of where its root falls in the
        // global sibling/number order, so a group's tasks render as one contiguous block instead of scattered
        // wherever each of that group's root tasks happens to sit relative to other groups' root tasks.
        $segmentsByGroup = [];
        foreach ($segments as $segment) {
            $group = $rootGroups[$segment['rootId']] ?? null;
            if ($group !== null) {
                $segmentsByGroup[$group['id']][] = $segment['rows'];
            }
        }

        $decorated = [];
        $emittedGroups = [];
        foreach ($segments as $segment) {
            $group = $rootGroups[$segment['rootId']] ?? null;
            if ($group === null) {
                array_push($decorated, ...$segment['rows']);

                continue;
            }
            if (isset($emittedGroups[$group['id']])) {
                continue;
            }
            $emittedGroups[$group['id']] = true;
            $decorated[] = $this->virtualRow($group['id'], $group['title'], $group['projectTitle'], $group['color'], $group['projectAreaId']);
            foreach ($segmentsByGroup[$group['id']] as $segmentRows) {
                foreach ($segmentRows as $row) {
                    $row['ancestors'] = [$group['id'], ...$row['ancestors']];
                    $row['depth'] = count($row['ancestors']);
                    $decorated[] = $row;
                }
            }
        }

        return $decorated;
    }

    /** Wraps each project's already-contiguous run of root segments (guaranteed by clusterRootsByProject,
     * and preserved by insertGroupRoots since a Group only ever belongs to one project) with a virtual
     * project header, driven by each segment's own root task -- never a descendant's own membership,
     * which could legitimately differ from its root's ("Parent from another area") -- only in the All
     * Projects view.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function insertProjectRoots(array $rows, int $area): array
    {
        if ($area !== 0) {
            return $rows;
        }

        $rootProjects = [];
        foreach ($rows as $row) {
            if ($row['id'] === $row['treeRootId'] && isset($row['memberships'][0])) {
                $membership = $row['memberships'][0];
                $rootProjects[$row['id']] = ['id' => 'project-'.$membership['area'], 'title' => $membership['title'], 'color' => $membership['color'], 'projectAreaId' => $membership['area']];
            }
        }

        $decorated = [];
        $inserted = [];
        foreach ($this->segmentByTreeRoot($rows) as $segment) {
            $project = $rootProjects[$segment['rootId']] ?? null;
            if ($project === null) {
                array_push($decorated, ...$segment['rows']);

                continue;
            }
            if (! isset($inserted[$project['id']])) {
                $inserted[$project['id']] = true;
                $decorated[] = $this->virtualRow($project['id'], $project['title'], null, $project['color'], $project['projectAreaId']);
            }
            foreach ($segment['rows'] as $row) {
                $row['ancestors'] = [...$row['ancestors'], $project['id']];
                $row['depth'] = count($row['ancestors']);
                $decorated[] = $row;
            }
        }

        return $decorated;
    }

    /** Partitions rows into contiguous per-tree-root segments (a root row plus its already-contiguous
     * descendants, since buildTreeRows() emits each root's subtree depth-first) so every row belonging
     * to one root task can be moved as a unit.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{rootId: int, rows: list<array<string, mixed>>}>
     */
    private function segmentByTreeRoot(array $rows): array
    {
        $segments = [];
        $pending = [];
        foreach ($rows as $row) {
            if ($row['virtual']) {
                // A virtual header (e.g. a Group root inserted by insertGroupRoots) has no tree root of
                // its own -- glue it onto the real segment that follows it, rather than letting it start
                // an orphan segment attributed to no project.
                $pending[] = $row;

                continue;
            }
            if ($row['id'] === $row['treeRootId']) {
                $segments[] = ['rootId' => $row['treeRootId'], 'rows' => $pending];
                $pending = [];
            }
            $segments[array_key_last($segments)]['rows'][] = $row;
        }
        if ($pending !== []) {
            $segments[] = ['rootId' => 0, 'rows' => $pending];
        }

        return $segments;
    }

    /** @return array<string, mixed> */
    private function virtualRow(string $id, string $title, ?string $projectTitle, ?string $color, ?int $projectAreaId): array
    {
        return ['id' => $id, 'number' => null, 'title' => $title, 'state' => 'OPEN', 'parent' => null,
            'remoteParent' => null, 'container' => true, 'knowledge' => false, 'memberships' => [], 'projectTitle' => $projectTitle, 'projectColor' => $color, 'projectAreaId' => $projectAreaId, 'labels' => [], 'labelData' => [],
            'context' => false, 'outsideArea' => false, 'ancestors' => [], 'depth' => 0, 'hasChildren' => true,
            'unresolvedParent' => false, 'virtual' => true, 'parentTitle' => null, 'treeRootId' => $id];
    }
}
