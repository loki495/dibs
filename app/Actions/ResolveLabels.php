<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubRepository;
use App\Models\Label;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ResolveLabels
{
    public function __construct(
        private readonly EnqueueGitHubPush $enqueue,
        private readonly ActivityRecorder $recorder,
    ) {}

    /**
     * Add labels by name to an already-selected set: an existing label in the repository is reused
     * (case-insensitively), a missing one is created locally and its creation queued. Blank names are
     * skipped and the result never repeats a label. Runs inside the caller's transaction.
     *
     * @param  Collection<int, Label>  $labels
     * @param  list<string>  $newNames
     * @return Collection<int, Label>
     */
    public function handle(GitHubRepository $repository, Collection $labels, array $newNames): Collection
    {
        $resolved = collect($labels->all());
        foreach ($newNames as $name) {
            $name = Str::lower(trim($name));
            if ($name === '') {
                continue;
            }
            $existing = Label::query()->where('repository_id', $repository->id)->whereRaw('LOWER(name) = LOWER(?)', [$name])->first();
            if ($existing instanceof Label) {
                $resolved->push($existing);

                continue;
            }
            $label = Label::create([
                'repository_id' => $repository->id,
                'github_node_id' => null,
                'name' => $name,
                'color' => '6B7280',
                'is_available' => true,
            ]);
            $this->enqueue->handle('create_label', 'label', $label->id, ['name' => $label->name, 'color' => '6B7280', 'description' => null], 'label:create:'.$label->id);
            $this->recorder->change('ResolveLabels', $label, 'Created label', $this->recorder->diffModel($label, ['name', 'color']));
            $resolved->push($label);
        }

        return $resolved->unique('id')->values();
    }
}
