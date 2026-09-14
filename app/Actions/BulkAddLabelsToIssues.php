<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use App\Models\Label;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-adds labels to many tasks at once. Additive rather than a full replace ("set"), since a
 * bulk operation that silently wipes each task's existing labels is a much riskier default than
 * one that only adds -- a task keeps whatever labels it already had, plus the new ones.
 */
class BulkAddLabelsToIssues
{
    /**
     * @param  list<int>  $issueIds
     * @param  list<int>  $labelIds
     * @return int number of tasks actually changed
     */
    public function handle(array $issueIds, array $labelIds): int
    {
        $labels = Label::query()->where('is_available', true)->whereIn('id', $labelIds)->get();
        if ($labels->count() !== count($labelIds)) {
            throw new TodoValidationException('One or more selected labels are no longer available. Refresh and try again.');
        }
        $issues = Issue::query()->where('is_available', true)->whereIn('id', $issueIds)->with('labels')->get();

        return DB::transaction(function () use ($issues, $labels): int {
            $updated = 0;
            foreach ($issues as $issue) {
                $currentIds = $issue->labels->where('is_available', true)->pluck('id')->all();
                $toAdd = $labels->reject(fn (Label $label): bool => in_array($label->id, $currentIds, true));
                if ($toAdd->isEmpty()) {
                    continue;
                }
                $issue->labels()->syncWithoutDetaching($toAdd->pluck('id'));
                app(EnqueueGitHubPush::class)->handle('add_issue_labels', 'issue', $issue->id, ['label_ids' => $toAdd->pluck('id')->all()], 'issue:labels:'.$issue->id.':'.now()->timestamp);
                $updated++;
            }

            return $updated;
        });
    }
}
