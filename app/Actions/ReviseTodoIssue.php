<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\Activity\ActivityRecorder;
use App\Support\LabelName;
use App\Support\TextReplacements;
use Illuminate\Support\Facades\DB;

class ReviseTodoIssue
{
    public function __construct(
        private readonly ResolveIdempotentWrite $idempotent,
        private readonly ActivityRecorder $recorder,
        private readonly ResolveLabels $resolveLabels,
        private readonly SyncIssueLabels $syncLabels,
        private readonly MoveIssueUnderParent $moveUnderParent,
        private readonly ResolveGroupOption $resolveGroup,
        private readonly SyncIssueLinks $syncLinks,
        private readonly SyncStepFields $syncStep,
        private readonly AssignIssueToProject $assignToProject,
    ) {}

    /**
     * @param  list<string>  $addLabels  names to attach; a missing label is created, matching is case-insensitive
     * @param  list<string>  $removeLabels  names to detach; one the issue does not carry is ignored
     * @param  list<array{issueId: int, commentId?: ?int}>  $addLinks
     * @param  list<array{issueId: int, commentId?: ?int}>  $removeLinks
     * @param  list<array{from: string, to: string, all?: bool}>  $replace  exact-string edits of the current body, instead of a whole new $body
     * @param  array<string, ?string>  $step  step fields to set (see SyncStepFields); the body is untouched
     * @param  list<int>|null  $blockedBy  the step's whole new blocked-by list; null leaves it alone
     * @param  ?int  $areaId  move to this Area (Project), or by title with $areaName, or $clearArea to remove it; the new membership starts with no Group or Priority unless one is given in the same call
     * @param  ?int  $priorityId  a Priority option of the (new) Area, or by name with $priorityName, or $clearPriority
     */
    public function handle(
        int $id,
        int $expectedRevision,
        ?string $title = null,
        ?string $body = null,
        ?int $groupId = null,
        ?string $note = null,
        ?string $idempotencyKey = null,
        array $addLabels = [],
        array $removeLabels = [],
        ?int $parentId = null,
        bool $clearParent = false,
        ?string $newGroupName = null,
        bool $clearGroup = false,
        array $addLinks = [],
        array $removeLinks = [],
        array $replace = [],
        array $step = [],
        ?array $blockedBy = null,
        ?int $areaId = null,
        ?string $areaName = null,
        bool $clearArea = false,
        ?int $priorityId = null,
        ?string $priorityName = null,
        bool $clearPriority = false,
    ): Issue {
        if ($body !== null && $replace !== []) {
            throw new TodoValidationException('Provide either a new body or replace edits, not both.');
        }
        if ($title === null && $body === null && $replace === [] && $groupId === null && $addLabels === [] && $removeLabels === []
            && $parentId === null && ! $clearParent && $newGroupName === null && ! $clearGroup && $addLinks === [] && $removeLinks === []
            && $step === [] && $blockedBy === null && $areaId === null && $areaName === null && ! $clearArea
            && $priorityId === null && $priorityName === null && ! $clearPriority) {
            throw new TodoValidationException('Provide a title, a body, a groupId, a Group name, an Area, a Priority, a parent, label, link or step field changes, or a combination to revise.');
        }
        if (count(array_filter([$groupId !== null, $newGroupName !== null, $clearGroup])) > 1) {
            throw new TodoValidationException('Choose only one of groupId, newGroupName or clearGroup.');
        }
        if (count(array_filter([$areaId !== null, $areaName !== null, $clearArea])) > 1) {
            throw new TodoValidationException('Choose only one of areaId, areaName or clearArea.');
        }
        if (count(array_filter([$priorityId !== null, $priorityName !== null, $clearPriority])) > 1) {
            throw new TodoValidationException('Choose only one of priorityId, priorityName or clearPriority.');
        }
        if ($areaName !== null && trim($areaName) === '') {
            throw new TodoValidationException('An Area name cannot be blank.');
        }
        if ($priorityName !== null && trim($priorityName) === '') {
            throw new TodoValidationException('A Priority name cannot be blank.');
        }
        if ($newGroupName !== null && trim($newGroupName) === '') {
            throw new TodoValidationException('A Group name cannot be blank.');
        }
        if ($parentId !== null && $clearParent) {
            throw new TodoValidationException('A parent cannot be both set and cleared in one revision.');
        }

        $addLabels = $this->labelNames($addLabels);
        $removeLabels = $this->labelNames($removeLabels);
        if (array_intersect($addLabels, $removeLabels) !== []) {
            throw new TodoValidationException('A label cannot be both added and removed in one revision.');
        }

        $revise = function () use ($id, $expectedRevision, $title, $body, $replace, $groupId, $note, $addLabels, $removeLabels, $parentId, $clearParent, $newGroupName, $clearGroup, $addLinks, $removeLinks, $step, $blockedBy, $areaId, $areaName, $clearArea, $priorityId, $priorityName, $clearPriority): Issue {
            $issue = Issue::query()->with(['projectItems' => fn ($query) => $query->where('is_available', true)->whereNull('archived_at')])->find($id);
            if (! $issue instanceof Issue) {
                throw new TodoRecordNotFoundException("No Todo issue with local id [{$id}].");
            }
            if (! $issue->is_available) {
                throw new TodoRecordUnavailableException("Issue #{$issue->github_number} (local id {$id}) is no longer available; it was removed or lost GitHub access.");
            }
            if ($issue->revision !== $expectedRevision) {
                throw new TodoStaleRevisionException($issue->id, "Issue #{$issue->github_number} (local id {$id}) has changed since expectedRevision was read. Reread it and reconcile before retrying.");
            }
            if ($replace !== []) {
                $body = TextReplacements::apply((string) $issue->body, $replace);
            }

            $parent = null;
            if ($parentId !== null) {
                $parent = Issue::query()->where('is_available', true)->find($parentId);
                if (! $parent instanceof Issue) {
                    throw new TodoValidationException('The selected parent is no longer available. Refresh and try again.');
                }
            }

            $areaChange = $areaId !== null || $areaName !== null || $clearArea;
            $groupChange = $groupId !== null || $newGroupName !== null || $clearGroup;
            $priorityChange = $priorityId !== null || $priorityName !== null || $clearPriority;
            $project = $areaChange ? $this->resolveArea($areaId, $areaName) : $issue->projectItems->first()?->project;
            if ($clearArea && ($groupChange || $priorityChange)) {
                throw new TodoValidationException('An issue with no area cannot have a Group or Priority; do not combine clearArea with Group or Priority changes.');
            }
            if (($groupChange || $priorityChange) && ! $project instanceof GitHubProject) {
                throw new TodoValidationException("Issue #{$issue->github_number} (local id {$id}) has no area assigned yet; assign one before setting a Group or Priority.");
            }
            if ($groupId !== null) {
                $group = ProjectFieldOption::query()->with('field')->find($groupId);
                if (! $group instanceof ProjectFieldOption || $group->field?->semantic_key !== 'group' || $group->field->project_id !== $project?->id) {
                    throw new TodoValidationException('The selected Group is not available in this area. Refresh and try again.');
                }
            }
            $priorityOptionId = $priorityChange && ! $clearPriority ? $this->resolvePriority($project, $priorityId, $priorityName) : null;

            // attempts: 3 — same transient "database is locked" race against the scheduler's
            // push-queue drain as CompleteTodoTask; see that Action for the observed incident.
            return DB::transaction(function () use ($issue, $title, $body, $areaChange, $project, $groupChange, $priorityChange, $priorityOptionId, $groupId, $note, $addLabels, $removeLabels, $parent, $clearParent, $newGroupName, $addLinks, $removeLinks, $step, $blockedBy): Issue {
                $changes = array_filter(['title' => $title, 'body' => $body], fn ($value): bool => $value !== null);
                $issue->update([...$changes, 'revision' => $issue->revision + 1]);
                $this->recorder->changeModel('ReviseTodoIssue', $issue, 'Revised');
                if ($changes !== []) {
                    app(EnqueueGitHubPush::class)->handle('update_issue_body', 'issue', $issue->id, ['title' => $issue->title, 'body' => $issue->body], 'issue:revise:'.$issue->id.':'.now()->timestamp);
                }
                $item = $areaChange ? $this->assignToProject->handle($issue, $project) : $issue->projectItems->first();
                if ($item instanceof ProjectItem && $priorityChange) {
                    $previousPriorityId = $item->priority_option_id;
                    if ($previousPriorityId !== $priorityOptionId) {
                        $item->update(['priority_option_id' => $priorityOptionId]);
                        $this->recorder->change('ReviseTodoIssue', $issue, $priorityOptionId === null ? 'Cleared priority' : 'Changed priority', ['priority' => [
                            'from' => $this->recorder->nameOf(ProjectFieldOption::class, $previousPriorityId),
                            'to' => $this->recorder->nameOf(ProjectFieldOption::class, $priorityOptionId),
                        ]]);
                        $push = $priorityOptionId === null
                            ? ['clear_project_item_priority', [], 'project_item:clear_priority:']
                            : ['set_project_item_priority', ['priority_option_id' => $priorityOptionId], 'project_item:priority:'];
                        app(EnqueueGitHubPush::class)->handle($push[0], 'project_item', $item->id, $push[1], $push[2].$item->id.':'.now()->timestamp);
                    }
                }
                if ($item instanceof ProjectItem && $groupChange) {
                    $targetGroupId = $newGroupName !== null ? $this->resolveGroup->handle($item->project, $newGroupName)->id : $groupId;
                    $previousGroupId = $item->group_option_id;
                    if ($targetGroupId !== $previousGroupId || $targetGroupId !== null) {
                        $item->update(['group_option_id' => $targetGroupId]);
                        if ($previousGroupId !== $targetGroupId) {
                            $this->recorder->change('ReviseTodoIssue', $issue, $targetGroupId === null ? 'Cleared group' : 'Changed group', ['group' => [
                                'from' => $this->recorder->nameOf(ProjectFieldOption::class, $previousGroupId),
                                'to' => $this->recorder->nameOf(ProjectFieldOption::class, $targetGroupId),
                            ]]);
                        }
                        $push = $targetGroupId === null
                            ? ['clear_project_item_group', [], 'project_item:clear_group:']
                            : ['set_project_item_group', ['group_option_id' => $targetGroupId], 'project_item:group:'];
                        app(EnqueueGitHubPush::class)->handle($push[0], 'project_item', $item->id, $push[1], $push[2].$item->id.':'.now()->timestamp);
                    }
                }
                if ($parent instanceof Issue || $clearParent) {
                    $this->moveUnderParent->handle($issue, $parent);
                }
                if ($addLabels !== [] || $removeLabels !== []) {
                    $kept = $issue->labels()->where('is_available', true)->get()
                        ->reject(fn (Label $label): bool => in_array(LabelName::normalize($label->name), $removeLabels, true));
                    $this->syncLabels->handle($issue, $this->resolveLabels->handle($issue->repository, $kept, $addLabels));
                }
                $this->syncLinks->handle($issue, $addLinks, $removeLinks);
                $this->syncStep->handle($issue, $step, $blockedBy);
                if ($note !== null && trim($note) !== '') {
                    app(CreateTodoComment::class)->handle($issue->id, '**Revision note:** '.trim($note));
                }

                return $issue->fresh();
            }, 3);
        };

        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $revise();
        }

        return $this->idempotent->handle(
            $idempotencyKey,
            'issue',
            fn (int $subjectId) => Issue::find($subjectId),
            $revise,
        );
    }

    private function resolveArea(?int $areaId, ?string $areaName): ?GitHubProject
    {
        if ($areaId === null && $areaName === null) {
            return null;
        }
        $query = GitHubProject::query()->where('is_available', true);
        $project = $areaId !== null
            ? $query->find($areaId)
            : $query->whereRaw('LOWER(title) = LOWER(?)', [trim((string) $areaName)])->first();
        if (! $project instanceof GitHubProject) {
            throw new TodoValidationException('The selected Area is not available. Check todo_metadata for valid ones.');
        }

        return $project;
    }

    private function resolvePriority(?GitHubProject $project, ?int $priorityId, ?string $priorityName): int
    {
        $field = $project?->fields()->where('semantic_key', 'priority')->where('is_available', true)->first();
        $option = $field instanceof ProjectField
            ? ($priorityId !== null
                ? $field->options()->find($priorityId)
                : $field->options()->whereRaw('LOWER(name) = LOWER(?)', [trim((string) $priorityName)])->first())
            : null;
        if (! $option instanceof ProjectFieldOption) {
            throw new TodoValidationException('The selected Priority is not available in this area. Refresh and try again.');
        }

        return $option->id;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function labelNames(array $names): array
    {
        $normalized = array_map(LabelName::normalize(...), $names);
        if (in_array('', $normalized, true)) {
            throw new TodoValidationException('A label name cannot be blank.');
        }

        return array_values(array_unique($normalized));
    }
}
