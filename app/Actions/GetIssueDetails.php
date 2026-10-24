<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Support\KnowledgeLabels;
use Illuminate\Support\Str;

class GetIssueDetails
{
    public function __construct(private readonly DescribeTodoClaim $claim, private readonly DescribeIssueLinks $links) {}

    /** @return array<string, mixed> */
    public function handle(int $id): array
    {
        $issue = Issue::query()->where('is_available', true)->with([
            'repository', 'labels' => fn ($query) => $query->where('is_available', true),
            'projectItems' => fn ($query) => $query->where('is_available', true)->whereNull('archived_at')->whereHas('project', fn ($project) => $project->where('is_available', true)),
            'projectItems.project', 'projectItems.statusOption', 'projectItems.groupOption', 'projectItems.priorityOption',
            'comments' => fn ($query) => $query->where('is_available', true)->orderBy('remote_created_at'),
            'parent' => fn ($query) => $query->where('is_available', true)->with('labels'),
            'children' => fn ($query) => $query->where('is_available', true)->with('labels')->orderBy('sibling_position'),
        ])->findOrFail($id);

        // The most recently created closing comment, not the most recently pushed one — a local-first
        // closing comment has no remote_created_at yet, so id order is what "current close" means here.
        $closingComment = $issue->state === 'CLOSED'
            ? $issue->comments->where('kind', Comment::KIND_CLOSING)->sortByDesc('id')->first()
            : null;

        return [
            'issue' => $issue, 'body' => $this->renderMarkdown($issue->body ?? ''),
            // The current closing comment gets its own highlighted block instead, so it isn't shown twice.
            'comments' => $issue->comments
                ->reject(fn (Comment $comment): bool => $closingComment instanceof Comment && $comment->is($closingComment))
                ->map(fn (Comment $comment): array => ['id' => $comment->id, 'author' => $comment->author_login,
                    'date' => $comment->remote_created_at?->timezone(config('dibs.timezone'))->format('M j, Y'), 'body' => $this->renderMarkdown($comment->body)]),
            'closing' => $issue->state === 'CLOSED' ? [
                'reason' => $issue->state_reason,
                'note' => $closingComment?->body !== null && $closingComment->body !== '' ? $this->renderMarkdown($closingComment->body) : null,
                'references' => $closingComment?->references,
                'closedAt' => ($issue->closed_at ?? ($closingComment !== null ? $closingComment->created_at : $issue->updated_at))->timezone(config('dibs.timezone'))->format('M j, Y'),
            ] : null,
            'claim' => $this->claim->handle($issue->id),
            'parent' => $issue->parent instanceof Issue ? $this->summarize($issue->parent) : null,
            'children' => $issue->children->map(fn (Issue $child): array => $this->summarize($child))->all(),
            'links' => $this->links->outgoing($issue, forAgent: false),
            'linkedBy' => $this->links->incoming($issue, forAgent: false),
            'step' => array_filter([__('Done when') => $issue->done_when, __('Evidence') => $issue->evidence, __('Files likely') => $issue->files_likely], fn (?string $value): bool => $value !== null),
            'blockedBy' => $issue->blockedBy()->where('is_available', true)->with('labels')->get()->map(fn (Issue $blocker): array => $this->summarize($blocker))->all(),
            'pushQueuePending' => GitHubPushQueueItem::query()->where('target_type', 'issue')->where('target_id', $issue->id)->whereIn('status', ['pending', 'failed', 'needs_attention'])->exists(),
        ];
    }

    /** @return array<string, mixed> */
    private function summarize(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'number' => $issue->github_number,
            'title' => $issue->title,
            'state' => $issue->state,
            'isKnowledge' => $issue->labels->pluck('name')->intersect(KnowledgeLabels::NAMES)->isNotEmpty(),
        ];
    }

    private function renderMarkdown(string $markdown): string
    {
        return Str::markdown(strip_tags($markdown), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
