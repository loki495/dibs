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
    ): Issue {
        if ($title === null && $body === null && $groupId === null && $addLabels === [] && $removeLabels === []) {
            throw new TodoValidationException('Provide a title, a body, a groupId, label changes, or a combination to revise.');
        }

        $addLabels = $this->labelNames($addLabels);
        $removeLabels = $this->labelNames($removeLabels);
        if (array_intersect($addLabels, $removeLabels) !== []) {
            throw new TodoValidationException('A label cannot be both added and removed in one revision.');
        }

        $revise = function () use ($id, $expectedRevision, $title, $body, $groupId, $note, $addLabels, $removeLabels): Issue {
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

            $item = null;
            if ($groupId !== null) {
                $item = $issue->projectItems->first();
                if (! $item instanceof ProjectItem) {
                    throw new TodoValidationException("Issue #{$issue->github_number} (local id {$id}) has no area assigned yet; assign one before setting a Group.");
                }
                $group = ProjectFieldOption::query()->with('field')->find($groupId);
                if (! $group instanceof ProjectFieldOption || $group->field?->semantic_key !== 'group' || $group->field->project_id !== $item->project_id) {
                    throw new TodoValidationException('The selected Group is not available in this area. Refresh and try again.');
                }
            }

            // attempts: 3 — same transient "database is locked" race against the scheduler's
            // push-queue drain as CompleteTodoTask; see that Action for the observed incident.
            return DB::transaction(function () use ($issue, $title, $body, $item, $groupId, $note, $addLabels, $removeLabels): Issue {
                $changes = array_filter(['title' => $title, 'body' => $body], fn ($value): bool => $value !== null);
                $issue->update([...$changes, 'revision' => $issue->revision + 1]);
                $this->recorder->changeModel('ReviseTodoIssue', $issue, 'Revised');
                if ($changes !== []) {
                    app(EnqueueGitHubPush::class)->handle('update_issue_body', 'issue', $issue->id, ['title' => $issue->title, 'body' => $issue->body], 'issue:revise:'.$issue->id.':'.now()->timestamp);
                }
                if ($item instanceof ProjectItem) {
                    $previousGroupId = $item->group_option_id;
                    $item->update(['group_option_id' => $groupId]);
                    if ($previousGroupId !== $groupId) {
                        $this->recorder->change('ReviseTodoIssue', $issue, 'Changed group', ['group' => [
                            'from' => $this->recorder->nameOf(ProjectFieldOption::class, $previousGroupId),
                            'to' => $this->recorder->nameOf(ProjectFieldOption::class, $groupId),
                        ]]);
                    }
                    app(EnqueueGitHubPush::class)->handle('set_project_item_group', 'project_item', $item->id, ['group_option_id' => $groupId], 'project_item:group:'.$item->id.':'.now()->timestamp);
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
