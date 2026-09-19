<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Models\Label;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Collection;

class SyncIssueLabels
{
    public function __construct(
        private readonly EnqueueGitHubPush $enqueue,
        private readonly ActivityRecorder $recorder,
    ) {}

    /**
     * Make the issue's labels exactly the given set locally and queue only the difference for GitHub.
     *
     * @param  Collection<int, Label>  $labels
     */
    public function handle(Issue $issue, Collection $labels): void
    {
        $current = $issue->labels()->where('is_available', true)->get();
        $currentIds = $current->pluck('id')->all();
        $requestedIds = $labels->pluck('id')->all();
        $addIds = array_values(array_diff($requestedIds, $currentIds));
        $removeIds = array_values(array_diff($currentIds, $requestedIds));
        if ($addIds === [] && $removeIds === []) {
            return;
        }

        $issue->labels()->sync($requestedIds);
        $this->enqueue->handle('set_issue_labels', 'issue', $issue->id, ['add_label_ids' => $addIds, 'remove_label_ids' => $removeIds], 'issue:labels:'.$issue->id.':'.now()->timestamp);

        $added = $this->names($labels->whereIn('id', $addIds));
        $removed = $this->names($current->whereIn('id', $removeIds));
        $this->recorder->change(
            'SyncIssueLabels',
            $issue,
            'Changed labels: '.implode(', ', [...array_map(fn (string $name): string => '+'.$name, $added), ...array_map(fn (string $name): string => '-'.$name, $removed)]),
            ['labels' => ['from' => $this->names($current), 'to' => $this->names($labels)]],
        );
    }

    /**
     * @param  Collection<int, Label>  $labels
     * @return list<string>
     */
    private function names(Collection $labels): array
    {
        return $labels->pluck('name')->sort()->values()->all();
    }
}
