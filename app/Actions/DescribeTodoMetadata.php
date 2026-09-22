<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubProject;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Everything a caller can attach to a task (Projects, Groups, Priorities, labels) with the ids to pass and the
 * rules for using or creating each, searchable by name. Each kind is capped so a large workspace stays bounded.
 */
class DescribeTodoMetadata
{
    public const int LIMIT = 200;

    public const array KINDS = ['areas', 'groups', 'priorities', 'labels'];

    public const array RULES = [
        'areas' => 'Pass an area id from this list as `area` on todo_create or todo_scaffold_plan.',
        'groups' => 'Attach with groupId, which must belong to the task\'s `area`, or create one by name with newGroupName on todo_create.',
        'priorities' => 'Attach with priorityId, which must belong to the task\'s `area`.',
        'labels' => 'Stored lowercase with single spaces, so any casing resolves to the same label. Attach by id with labelIds, or by name with labelNames on todo_create, which also creates any that do not exist yet.',
    ];

    /**
     * @param  list<string>  $kinds  which of KINDS to return; empty means all
     * @return array<string, mixed>
     */
    public function handle(?string $query = null, array $kinds = [], ?int $area = null): array
    {
        if ($area !== null && ! GitHubProject::query()->where('is_available', true)->whereKey($area)->exists()) {
            throw ValidationException::withMessages(['area' => "No available area has id {$area}."]);
        }

        $wanted = $kinds === [] ? self::KINDS : array_values(array_intersect(self::KINDS, $kinds));
        $term = trim((string) $query);
        $pattern = $term === '' ? null : '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $result = [];
        $truncated = [];

        foreach ($wanted as $kind) {
            $rows = match ($kind) {
                'areas' => $this->areas($pattern, $area),
                'groups' => $this->groups($pattern, $area),
                'priorities' => $this->priorities($pattern, $area),
                default => $this->labels($pattern),
            };

            if (count($rows) > self::LIMIT) {
                $truncated[] = $kind;
                $rows = array_slice($rows, 0, self::LIMIT);
            }

            $result[$kind] = $rows;
        }

        $result['rules'] = array_intersect_key(self::RULES, array_flip($wanted));

        return $truncated === [] ? $result : [...$result, 'truncated' => $truncated];
    }

    /** @return list<array<string, mixed>> */
    private function areas(?string $pattern, ?int $area): array
    {
        return GitHubProject::query()->where('is_available', true)
            ->when($area !== null, fn (Builder $projects) => $projects->whereKey($area))
            ->when($pattern !== null, fn (Builder $projects) => $projects->whereRaw("title LIKE ? ESCAPE '\\'", [$pattern]))
            ->withCount(['items' => fn ($items) => $items->where('is_available', true)->whereNull('archived_at')
                ->whereHas('issue', fn ($issue) => $issue->where('is_available', true)->where('state', 'OPEN'))])
            ->orderBy('title')->limit(self::LIMIT + 1)->get()
            ->map(fn (GitHubProject $project): array => ['id' => $project->id, 'title' => $project->title, 'color' => $project->color, 'openTaskCount' => $project->items_count])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function groups(?string $pattern, ?int $area): array
    {
        $options = $this->options('group', $pattern, $area);
        $open = ProjectItem::query()->whereIn('group_option_id', $options->modelKeys())
            ->where('is_available', true)->whereNull('archived_at')
            ->whereHas('issue', fn ($issue) => $issue->where('is_available', true)->where('state', 'OPEN'))
            ->selectRaw('group_option_id, COUNT(*) AS aggregate')->groupBy('group_option_id')->pluck('aggregate', 'group_option_id');

        return $options->map(fn (ProjectFieldOption $option): array => [
            'id' => $option->id, 'name' => $option->name, 'areaId' => $option->field->project_id, 'area' => $option->field->project->title,
            'openTaskCount' => (int) ($open[$option->id] ?? 0),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function priorities(?string $pattern, ?int $area): array
    {
        return $this->options('priority', $pattern, $area)->map(fn (ProjectFieldOption $option): array => [
            'id' => $option->id, 'name' => $option->name, 'areaId' => $option->field->project_id, 'area' => $option->field->project->title,
        ])->all();
    }

    /** @return Collection<int, ProjectFieldOption> */
    private function options(string $semanticKey, ?string $pattern, ?int $area)
    {
        return ProjectFieldOption::query()
            ->whereHas('field', fn (Builder $field) => $field->where('is_available', true)->where('semantic_key', $semanticKey)
                ->whereHas('project', fn (Builder $project) => $project->where('is_available', true))
                ->when($area !== null, fn (Builder $field) => $field->where('project_id', $area)))
            ->when($pattern !== null, fn (Builder $options) => $options->whereRaw("name LIKE ? ESCAPE '\\'", [$pattern]))
            ->with('field.project')
            ->orderBy(ProjectField::query()->select('project_id')->whereColumn('project_fields.id', 'project_field_options.project_field_id'))
            ->orderBy('position')->orderBy('id')
            ->limit(self::LIMIT + 1)->get();
    }

    /** @return list<array<string, mixed>> */
    private function labels(?string $pattern): array
    {
        return Label::query()->where('is_available', true)
            ->when($pattern !== null, fn (Builder $labels) => $labels->where(fn (Builder $match) => $match
                ->whereRaw("name LIKE ? ESCAPE '\\'", [$pattern])->orWhereRaw("description LIKE ? ESCAPE '\\'", [$pattern])))
            ->withCount([
                'issues as total_count' => fn ($issues) => $issues->where('is_available', true),
                'issues as open_count' => fn ($issues) => $issues->where('is_available', true)->where('state', 'OPEN'),
            ])
            ->orderBy('name')->limit(self::LIMIT + 1)->get()
            ->map(fn (Label $label): array => [
                'id' => $label->id, 'name' => $label->name, 'description' => $label->description, 'color' => $label->color,
                'openCount' => (int) $label->getAttribute('open_count'), 'totalCount' => (int) $label->getAttribute('total_count'),
            ])->all();
    }
}
