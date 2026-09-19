<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Support\Facades\DB;

class UpdateTodoIssue
{
    public function __construct(
        private readonly EnqueueGitHubPush $enqueue,
        private readonly ResolveGroupOption $resolveGroup,
        private readonly ResolveLabels $resolveLabels,
        private readonly SyncIssueLabels $syncLabels,
        private readonly MoveIssueUnderParent $moveUnderParent,
        private readonly AssignIssueToProject $assignToProject,
        private readonly ApplyProjectItemFields $applyFields,
    ) {}

    /**
     * Save a full edit of an issue: title, body, labels, parent, area, Group and Priority. Optimistic
     * concurrency: pass the revision last read as $expectedRevision; every save bumps it by one. An id
     * of 0 or null for the area, parent, Group or Priority means "none".
     *
     * @param  list<int>  $labelIds
     * @param  list<string>  $newLabelNames
     *
     * @throws TodoValidationException
     * @throws TodoRecordUnavailableException
     * @throws TodoRecordNotFoundException
     * @throws TodoStaleRevisionException
     */
    public function handle(
        int $id,
        int $expectedRevision,
        string $title,
        ?string $body = null,
        ?int $areaId = null,
        ?int $parentId = null,
        ?int $groupId = null,
        ?int $priorityId = null,
        array $labelIds = [],
        ?string $newGroupName = null,
        array $newLabelNames = [],
    ): Issue {
        $title = trim($title);
        if ($title === '') {
            throw new TodoValidationException('A title is required.');
        }
        $issue = Issue::query()->where('is_available', true)->find($id);
        if (! $issue instanceof Issue) {
            throw new TodoRecordUnavailableException("No available Todo issue with local id [{$id}]; it was removed or lost GitHub access.");
        }
        if ($issue->revision !== $expectedRevision) {
            throw new TodoStaleRevisionException($issue->id, "Issue (local id {$id}) has changed since expectedRevision was read. Reread it and reconcile before retrying.");
        }
        $repository = GitHubRepository::query()->where('full_name', config('github.owner').'/'.config('github.repository'))->first();
        if (! $repository instanceof GitHubRepository) {
            throw new TodoRecordNotFoundException('The repository is not configured or not available locally. Refresh and try again.');
        }

        $project = $areaId > 0 ? GitHubProject::query()->where('is_available', true)->find($areaId) : null;
        $parent = $parentId > 0 ? Issue::query()->where('is_available', true)->find($parentId) : null;
        $labelIds = array_values(array_unique($labelIds));
        $labels = Label::query()->where('is_available', true)->whereIn('id', $labelIds)->get();
        if (($areaId > 0 && ! $project instanceof GitHubProject) || ($parentId > 0 && ! $parent instanceof Issue) || $labels->count() !== count($labelIds)) {
            throw new TodoValidationException('One or more selected task fields are no longer available. Refresh and try again.');
        }
        $group = $groupId > 0 ? ProjectFieldOption::query()->with('field')->find($groupId) : null;
        if ($groupId > 0 && (! $group instanceof ProjectFieldOption || ! $project instanceof GitHubProject || $group->field?->semantic_key !== 'group' || $group->field->project_id !== $project->id)) {
            throw new TodoValidationException('The selected Group is not available in this Area. Refresh and try again.');
        }
        $priority = $priorityId > 0 ? ProjectFieldOption::query()->with('field')->find($priorityId) : null;
        if ($priorityId > 0 && (! $priority instanceof ProjectFieldOption || ! $project instanceof GitHubProject || $priority->field?->semantic_key !== 'priority' || $priority->field->project_id !== $project->id)) {
            throw new TodoValidationException('The selected Priority is not available in this Area. Refresh and try again.');
        }
        $newGroupName = trim((string) $newGroupName);
        if ($newGroupName !== '' && ! $project instanceof GitHubProject) {
            throw new TodoValidationException('Choose an area before creating a Group.');
        }

        // attempts: 3 — same transient "database is locked" race against the scheduler's
        // push-queue drain as CompleteTodoTask; see that Action for the observed incident.
        return DB::transaction(function () use ($issue, $repository, $project, $parent, $group, $priority, $labels, $title, $body, $newGroupName, $newLabelNames): Issue {
            if ($newGroupName !== '') {
                $group = $this->resolveGroup->handle($project, $newGroupName);
            }
            $labels = $this->resolveLabels->handle($repository, $labels, $newLabelNames);

            $issue->update(['title' => $title, 'body' => $body === '' ? null : $body, 'revision' => $issue->revision + 1]);
            $this->enqueue->handle('update_issue_body', 'issue', $issue->id, ['title' => $issue->title, 'body' => $issue->body], 'issue:update:'.$issue->id.':'.now()->timestamp);

            $this->syncLabels->handle($issue, $labels);
            $this->moveUnderParent->handle($issue, $parent);
            $item = $this->assignToProject->handle($issue, $project);
            if ($item instanceof ProjectItem) {
                $this->applyFields->handle($item, $group, $priority);
            }

            return $issue->refresh();
        }, 3);
    }
}
