<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\Activity\ActivityRecorder;
use App\Support\LabelName;
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
    ) {}

    /**
     * @param  list<string>  $addLabels  names to attach; a missing label is created, matching is case-insensitive
     * @param  list<string>  $removeLabels  names to detach; one the issue does not carry is ignored
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
    ): Issue {
        if ($title === null && $body === null && $groupId === null && $addLabels === [] && $removeLabels === []
            && $parentId === null && ! $clearParent && $newGroupName === null && ! $clearGroup) {
            throw new TodoValidationException('Provide a title, a body, a groupId, a Group name, a parent, label changes, or a combination to revise.');
        }
        if (count(array_filter([$groupId !== null, $newGroupName !== null, $clearGroup])) > 1) {
            throw new TodoValidationException('Choose only one of groupId, newGroupName or clearGroup.');
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

        $revise = function () use ($id, $expectedRevision, $title, $body, $groupId, $note, $addLabels, $removeLabels, $parentId, $clearParent, $newGroupName, $clearGroup): Issue {
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

            $parent = null;
            if ($parentId !== null) {
                $parent = Issue::query()->where('is_available', true)->find($parentId);
                if (! $parent instanceof Issue) {
                    throw new TodoValidationException('The selected parent is no longer available. Refresh and try again.');
                }
            }

            $item = null;
            if ($groupId !== null || $newGroupName !== null || $clearGroup) {
                $item = $issue->projectItems->first();
                if (! $item instanceof ProjectItem) {
                    throw new TodoValidationException("Issue #{$issue->github_number} (local id {$id}) has no area assigned yet; assign one before setting a Group.");
                }
                if ($groupId !== null) {
                    $group = ProjectFieldOption::query()->with('field')->find($groupId);
                    if (! $group instanceof ProjectFieldOption || $group->field?->semantic_key !== 'group' || $group->field->project_id !== $item->project_id) {
                        throw new TodoValidationException('The selected Group is not available in this area. Refresh and try again.');
                    }
                }
            }

            // attempts: 3 — same transient "database is locked" race against the scheduler's
            // push-queue drain as CompleteTodoTask; see that Action for the observed incident.
            return DB::transaction(function () use ($issue, $title, $body, $item, $groupId, $note, $addLabels, $removeLabels, $parent, $clearParent, $newGroupName): Issue {
                $changes = array_filter(['title' => $title, 'body' => $body], fn ($value): bool => $value !== null);
                $issue->update([...$changes, 'revision' => $issue->revision + 1]);
                $this->recorder->changeModel('ReviseTodoIssue', $issue, 'Revised');
                if ($changes !== []) {
                    app(EnqueueGitHubPush::class)->handle('update_issue_body', 'issue', $issue->id, ['title' => $issue->title, 'body' => $issue->body], 'issue:revise:'.$issue->id.':'.now()->timestamp);
                }
                if ($item instanceof ProjectItem) {
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
