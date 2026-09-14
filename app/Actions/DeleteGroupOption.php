<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a Group option outright -- unlike Issues, ProjectFieldOption has no soft-delete
 * flag, and project_items.group_option_id already nulls itself on delete via its foreign
 * key. Any task currently in this Group is left in place, just ungrouped, matching how
 * deleting a parent task promotes rather than cascades by default. The GitHub identifiers
 * needed to remove the option remotely are captured in the push payload up front, since the
 * local row (and its github_option_id) won't exist any more by the time the queue drains.
 */
class DeleteGroupOption
{
    public function handle(ProjectFieldOption $option): void
    {
        $option->loadMissing('field');
        $field = $option->field;
        DB::transaction(function () use ($option, $field): void {
            ProjectItem::query()->where('group_option_id', $option->id)->update(['group_option_id' => null]);
            $githubOptionId = $option->github_option_id;
            $fieldGithubNodeId = $field instanceof ProjectField ? $field->github_node_id : null;
            $optionId = $option->id;
            $option->delete();
            if ($githubOptionId !== null && $fieldGithubNodeId !== null) {
                app(EnqueueGitHubPush::class)->handle('delete_group_option', 'project_field_option', $optionId, ['github_option_id' => $githubOptionId, 'field_github_node_id' => $fieldGithubNodeId], 'group_option:delete:'.$optionId);
            }
        });
    }
}
