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
    /**
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    public function handle(int $area = 0, string $view = 'tasks', string $search = '', string $state = 'OPEN', int $group = 0, array $labels = [], int $priority = 0, bool $rankPriority = false): array
    {
        $issues = Issue::query()->where('is_available', true)->with([
            'labels' => fn ($query) => $query->where('is_available', true),
            'projectItems' => fn ($query) => $query->where('is_available', true)->whereNull('archived_at')->whereHas('project', fn ($project) => $project->where('is_available', true)),
            'projectItems.project', 'projectItems.groupOption', 'projectItems.priorityOption', 'projectItems.statusOption',
        ])->orderBy('sibling_position')->orderBy('github_number')->get();
        $containerIds = $issues->pluck('parent_issue_id')->filter()->flip()->all();
        $requiresParent = in_array('parent', $labels, true);
        $requestedLabels = array_values(array_filter($labels, fn (string $label): bool => $label !== 'parent'));
        $today = now(config('todo.timezone'))->toDateString();
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
            $labelMatches = ($requiresParent === false || $container) && ($requestedLabels === [] || count(array_diff($requestedLabels, $names)) === 0);
            $priorityMatches = $priority === 0 || collect($memberships)->contains(fn (array $item): bool => $item['priority'] === (string) $priority && ($area === 0 || $item['area'] === $area));
            $matches[$issue->id] = $inArea && $inGroup && $modeMatches && $stateMatches && $searchMatches && $labelMatches && $priorityMatches;
            $nodes[$issue->id] = ['id' => $issue->id, 'number' => $issue->github_number, 'title' => $issue->title, 'state' => $issue->state,
                'parent' => $issue->parent_issue_id, 'remoteParent' => $issue->github_parent_node_id,
                'container' => $container, 'knowledge' => $knowledge, 'memberships' => $memberships, 'projectTitle' => $memberships[0]['title'] ?? null, 'projectColor' => $memberships[0]['color'] ?? null, 'labels' => $names, 'labelData' => $issue->labels->map(fn (Label $label): array => ['name' => $label->name, 'color' => ctype_xdigit((string) $label->color) && strlen((string) $label->color) === 6 ? '#'.$label->color : null])->all(),
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
        if ($rankPriority) {
            $priorityFor = function (int $id) use ($nodes, $area): int {
                $priorities = collect($nodes[$id]['memberships'])->filter(fn (array $membership): bool => $area === 0 || $membership['area'] === $area)
                    ->pluck('priority')->filter(fn (?string $value): bool => ctype_digit((string) $value))->map(fn (string $value): int => (int) $value);

                return $priorities->min() ?? 999;
            };
            foreach ($children as &$siblings) {
                usort($siblings, fn (int $left, int $right): int => $priorityFor($left) <=> $priorityFor($right));
            }
            unset($siblings);
        }
        $rows = [];
        $visited = [];
        $walk = function (int $id, array $ancestors = []) use (&$walk, &$rows, &$visited, $nodes, $children): void {
            if (isset($visited[$id])) {
                return;
            }
            $visited[$id] = true;
            $node = $nodes[$id];
            $rows[] = [...$node, 'ancestors' => $ancestors, 'depth' => count($ancestors), 'hasChildren' => count($children[$id] ?? []) > 0,
                'unresolvedParent' => $node['remoteParent'] !== null && ! isset($nodes[$node['parent']]), 'virtual' => false];
            foreach ($children[$id] ?? [] as $child) {
                $walk($child, [...$ancestors, $id]);
            }
        };
        foreach ($children[0] ?? [] as $id) {
            $walk($id);
        }
        foreach (array_keys($included) as $id) {
            $walk($id);
        }
        if ($group === 0 && $view !== 'daily') {
            $rows = $this->insertGroupRoots($rows, $area);
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

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function insertGroupRoots(array $rows, int $area): array
    {
        $rootGroups = [];
        foreach ($rows as $row) {
            if ($row['ancestors'] !== []) {
                continue;
            }
            foreach ($row['memberships'] as $membership) {
                if (($area === 0 || $membership['area'] === $area) && $membership['groupId'] !== null && $membership['group'] !== null) {
                    $rootGroups[$row['id']] = ['id' => 'group-'.$membership['groupId'], 'title' => $membership['group'], 'projectTitle' => $membership['title'], 'color' => $membership['color']];
                    break;
                }
            }
        }

        $decorated = [];
        $inserted = [];
        foreach ($rows as $row) {
            $rootId = $row['ancestors'][0] ?? $row['id'];
            $group = $rootGroups[$rootId] ?? null;
            if ($group !== null) {
                if (! isset($inserted[$group['id']])) {
                    $decorated[] = ['id' => $group['id'], 'number' => null, 'title' => $group['title'], 'state' => 'OPEN', 'parent' => null,
                        'remoteParent' => null, 'container' => true, 'knowledge' => false, 'memberships' => [], 'projectTitle' => $group['projectTitle'], 'projectColor' => $group['color'], 'labels' => [], 'labelData' => [],
                        'context' => false, 'outsideArea' => false, 'ancestors' => [], 'depth' => 0, 'hasChildren' => true,
                        'unresolvedParent' => false, 'virtual' => true];
                    $inserted[$group['id']] = true;
                }
                $row['ancestors'] = [$group['id'], ...$row['ancestors']];
                $row['depth'] = count($row['ancestors']);
            }
            $decorated[] = $row;
        }

        return $decorated;
    }
}
