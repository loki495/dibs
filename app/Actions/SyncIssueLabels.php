<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Models\Label;
use Illuminate\Support\Collection;

class SyncIssueLabels
{
    public function __construct(private readonly EnqueueGitHubPush $enqueue) {}

    /**
     * Make the issue's labels exactly the given set locally and queue only the difference for GitHub.
     *
     * @param  Collection<int, Label>  $labels
     */
    public function handle(Issue $issue, Collection $labels): void
    {
        $currentIds = $issue->labels()->where('is_available', true)->pluck('labels.id')->all();
        $requestedIds = $labels->pluck('id')->all();
        $addIds = array_values(array_diff($requestedIds, $currentIds));
        $removeIds = array_values(array_diff($currentIds, $requestedIds));
        if ($addIds === [] && $removeIds === []) {
            return;
        }

        $issue->labels()->sync($requestedIds);
        $this->enqueue->handle('set_issue_labels', 'issue', $issue->id, ['add_label_ids' => $addIds, 'remove_label_ids' => $removeIds], 'issue:labels:'.$issue->id.':'.now()->timestamp);
    }
}
