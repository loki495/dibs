<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\Activity\ActivityRecorder;

class ApplyProjectItemFields
{
    public function __construct(
        private readonly EnqueueGitHubPush $enqueue,
        private readonly ActivityRecorder $recorder,
    ) {}

    /** Set or clear a project membership's Group and Priority locally, queueing each change for GitHub. */
    public function handle(ProjectItem $item, ?ProjectFieldOption $group, ?ProjectFieldOption $priority): void
    {
        $previousGroupId = $item->group_option_id;
        $previousPriorityId = $item->priority_option_id;

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

        $this->recordFieldChanges($item, $previousGroupId, $previousPriorityId);
    }

    private function recordFieldChanges(ProjectItem $item, ?int $previousGroupId, ?int $previousPriorityId): void
    {
        $changes = [];
        if ($previousGroupId !== $item->group_option_id) {
            $changes['group'] = ['from' => $this->recorder->nameOf(ProjectFieldOption::class, $previousGroupId), 'to' => $this->recorder->nameOf(ProjectFieldOption::class, $item->group_option_id)];
        }
        if ($previousPriorityId !== $item->priority_option_id) {
            $changes['priority'] = ['from' => $this->recorder->nameOf(ProjectFieldOption::class, $previousPriorityId), 'to' => $this->recorder->nameOf(ProjectFieldOption::class, $item->priority_option_id)];
        }

        if ($changes !== []) {
            $this->recorder->change('ApplyProjectItemFields', $item->issue, 'Changed '.implode(' and ', array_keys($changes)), $changes);
        }
    }
}
