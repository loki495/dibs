<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Support\IssueSummary;

class DescribeTodoIssue
{
    public const MAX_COMMENTS_PER_PAGE = 50;

    public const DEFAULT_COMMENTS_PER_PAGE = 20;

    /** @return array<string, mixed> */
    public function handle(int $id, bool $withComments = false, int $commentsPage = 1, int $commentsPerPage = self::DEFAULT_COMMENTS_PER_PAGE): array
    {
        $issue = Issue::query()->withCount(['children' => fn ($query) => $query->where('is_available', true)])
            ->with([
                'labels' => fn ($query) => $query->where('is_available', true),
                'projectItems' => fn ($query) => $query->where('is_available', true)
                    ->whereHas('project', fn ($project) => $project->where('is_available', true)),
                'projectItems.project', 'projectItems.groupOption', 'projectItems.priorityOption', 'projectItems.statusOption',
                'parent' => fn ($query) => $query->withCount(['children' => fn ($children) => $children->where('is_available', true)])->with(['labels' => fn ($labels) => $labels->where('is_available', true), 'projectItems' => fn ($items) => $items->where('is_available', true), 'projectItems.project', 'projectItems.groupOption', 'projectItems.priorityOption']),
                'children' => fn ($query) => $query->where('is_available', true)
                    ->withCount(['children as children_count' => fn ($grandchildren) => $grandchildren->where('is_available', true)])
                    ->with(['labels' => fn ($labels) => $labels->where('is_available', true), 'projectItems' => fn ($items) => $items->where('is_available', true), 'projectItems.project', 'projectItems.groupOption', 'projectItems.priorityOption']),
            ])->find($id);

        if (! $issue instanceof Issue) {
            throw new TodoRecordNotFoundException("No Todo issue with local id [{$id}].");
        }
        if (! $issue->is_available) {
            throw new TodoRecordUnavailableException("Issue #{$issue->github_number} (local id {$id}) is no longer available; it was removed or lost GitHub access.");
        }

        $result = [
            ...IssueSummary::from($issue),
            'body' => $issue->body,
            'parent' => $issue->parent instanceof Issue ? IssueSummary::from($issue->parent) : null,
            'children' => $issue->children->map(IssueSummary::from(...))->all(),
            'memberships' => $issue->projectItems->map(fn ($item): array => [
                'area' => $item->project instanceof GitHubProject ? ['id' => $item->project->id, 'title' => $item->project->title] : null,
                'group' => $item->groupOption?->name,
                'priority' => $item->priorityOption?->name,
                'status' => $item->statusOption?->name,
                'planned' => $item->planned_on?->toDateString(),
                'due' => $item->due_on?->toDateString(),
            ])->all(),
            'closing' => $this->closing($issue),
        ];

        if ($withComments) {
            $commentsPerPage = max(1, min($commentsPerPage, self::MAX_COMMENTS_PER_PAGE));
            $paginator = $issue->comments()->where('is_available', true)->orderBy('remote_created_at')
                ->paginate($commentsPerPage, ['*'], 'page', max(1, $commentsPage));
            $result['comments'] = [
                'items' => $paginator->getCollection()->map(fn ($comment): array => [
                    'id' => $comment->id, 'author' => $comment->author_login, 'body' => $comment->body,
                    'createdAt' => $comment->remote_created_at?->toIso8601String(), 'revision' => $comment->revision,
                ])->all(),
                'page' => $paginator->currentPage(), 'perPage' => $paginator->perPage(),
                'total' => $paginator->total(), 'lastPage' => $paginator->lastPage(),
            ];
        }

        return $result;
    }

    /**
     * The most recent close, if the issue is currently closed: its reason, the note and references
     * from that close's closing comment (if a note was given), and when it closed. Reopening and
     * closing an issue again leaves its earlier closing comments in history but reports only the
     * latest one here, alongside the current `state_reason`.
     *
     * @return array{reason: ?string, note: ?string, references: ?list<string>, closedAt: string}|null
     */
    private function closing(Issue $issue): ?array
    {
        if ($issue->state !== 'CLOSED') {
            return null;
        }

        $note = $issue->comments()->where('is_available', true)->closing()->latest('id')->first();

        return [
            'reason' => $issue->state_reason,
            'note' => $note?->body,
            'references' => $note?->references,
            'closedAt' => ($note !== null ? $note->created_at : $issue->updated_at)->toIso8601String(),
        ];
    }
}
