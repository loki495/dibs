<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;

class ApplyProjectItemFields
{
    public function __construct(private readonly EnqueueGitHubPush $enqueue) {}

    /** Set or clear a project membership's Group and Priority locally, queueing each change for GitHub. */
    public function handle(ProjectItem $item, ?ProjectFieldOption $group, ?ProjectFieldOption $priority): void
    {
        if ($group instanceof ProjectFieldOption) {
            $item->update(['group_option_id' => $group->id]);
            $this->enqueue->handle('set_project_item_group', 'project_item', $item->id, ['group_option_id' => $group->id], 'project_item:group:'.$item->id);
        } elseif ($item->group_option_id !== null) {
            $item->update(['group_option_id' => null]);
            $this->enqueue->handle('clear_project_item_group', 'project_item', $item->id, [], 'project_item:clear_group:'.$item->id.':'.now()->timestamp);
        }

        if ($priority instanceof ProjectFieldOption) {
            $item->update(['priority_option_id' => $priority->id]);
            $this->enqueue->handle('set_project_item_priority', 'project_item', $item->id, ['priority_option_id' => $priority->id], 'project_item:priority:'.$item->id);
        } elseif ($item->priority_option_id !== null) {
            $item->update(['priority_option_id' => null]);
            $this->enqueue->handle('clear_project_item_priority', 'project_item', $item->id, [], 'project_item:clear_priority:'.$item->id.':'.now()->timestamp);
        }
    }
}
