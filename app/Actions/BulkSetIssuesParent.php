<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use App\Support\ChildLimit;
use App\Support\WriteWarnings;
use Illuminate\Support\Facades\DB;

/**
 * Bulk equivalent of the single-task Parent edit. Skips a selected task that IS the chosen
 * parent (can't be its own parent) rather than failing the whole batch -- the same minimal
 * self-parenting guard the single-task edit form itself relies on; deeper cycle detection
 * (the chosen parent being a descendant of a selected task) is not attempted here, matching
 * that form's existing scope.
 */
class BulkSetIssuesParent
{
    public function __construct(private readonly ChildLimit $childLimit, private readonly WriteWarnings $warnings) {}

    /**
     * @param  list<int>  $issueIds
     * @return int number of tasks actually changed
     */
    public function handle(array $issueIds, ?int $parentId): int
    {
        $parent = $parentId !== null ? Issue::query()->where('is_available', true)->find($parentId) : null;
        if ($parentId !== null && ! $parent instanceof Issue) {
            throw new TodoValidationException('The selected parent is no longer available. Refresh and try again.');
        }
        $issues = Issue::query()->where('is_available', true)->whereIn('id', $issueIds)->get();

        $limitWarning = null;
        if ($parent instanceof Issue) {
            $incoming = $issues->filter(fn (Issue $issue): bool => ! $issue->is_temporary && $issue->id !== $parent->id && $issue->parent_issue_id !== $parent->id)->count();
            $limitWarning = $incoming > 0 ? $this->childLimit->check($parent, $incoming) : null;
        }

        $updated = DB::transaction(function () use ($issues, $parent): int {
            $updated = 0;
            foreach ($issues as $issue) {
                if ($parent instanceof Issue && $parent->id === $issue->id) {
                    continue;
                }
                if ($parent instanceof Issue && $issue->parent_issue_id !== $parent->id) {
                    $siblingPosition = (int) Issue::query()->where('parent_issue_id', $parent->id)->max('sibling_position') + 1;
                    $issue->update(['parent_issue_id' => $parent->id, 'sibling_position' => $siblingPosition]);
                    app(EnqueueGitHubPush::class)->handle('set_issue_parent', 'issue', $issue->id, ['parent_issue_id' => $parent->id], 'issue:parent:'.$issue->id.':'.now()->timestamp);
                    $updated++;
                } elseif ($parent === null && $issue->parent_issue_id !== null) {
                    $previousParentGithubNodeId = $issue->github_parent_node_id;
                    $issue->update(['parent_issue_id' => null, 'github_parent_node_id' => null, 'sibling_position' => 0]);
                    if ($previousParentGithubNodeId !== null) {
                        app(EnqueueGitHubPush::class)->handle('remove_issue_parent', 'issue', $issue->id, ['parent_github_node_id' => $previousParentGithubNodeId], 'issue:remove_parent:'.$issue->id.':'.now()->timestamp);
                    }
                    $updated++;
                }
            }

            return $updated;
        });
        if ($limitWarning !== null) {
            $this->warnings->add($limitWarning, $this->childLimit->key($parent));
        }

        return $updated;
    }
}
