<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Support\KnowledgeLabels;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GetIssueDetails
{
    public function __construct(private readonly DescribeTodoClaim $claim) {}

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

        $knowledge = $this->relatedKnowledge($issue);

        return [
            'issue' => $issue, 'body' => $this->renderMarkdown($issue->body ?? ''),
            'comments' => $issue->comments->map(fn ($comment): array => ['id' => $comment->id, 'author' => $comment->author_login,
                'date' => $comment->remote_created_at?->timezone(config('todo.timezone'))->format('M j, Y'), 'body' => $this->renderMarkdown($comment->body)]),
            'claim' => $this->claim->handle($issue->id),
            'parent' => $issue->parent instanceof Issue ? $this->summarize($issue->parent) : null,
            'children' => $issue->children->map(fn (Issue $child): array => $this->summarize($child))->all(),
            'knowledge' => $knowledge->map(fn (Issue $related): array => $this->summarize($related))->all(),
            'pushQueuePending' => GitHubPushQueueItem::query()->where('target_type', 'issue')->where('target_id', $issue->id)->whereIn('status', ['pending', 'failed', 'needs_attention'])->exists(),
        ];
    }

    /**
     * Sibling knowledge issues only — a knowledge-labeled child already appears in the 'children'
     * list with its own isKnowledge flag, so including it here too would just duplicate the entry.
     *
     * @return Collection<int, Issue>
     */
    private function relatedKnowledge(Issue $issue): Collection
    {
        if ($issue->parent_issue_id === null) {
            return collect();
        }

        $isKnowledge = fn (Issue $candidate): bool => $candidate->labels->pluck('name')->intersect(KnowledgeLabels::NAMES)->isNotEmpty();

        return Issue::query()->where('is_available', true)->where('parent_issue_id', $issue->parent_issue_id)->whereKeyNot($issue->id)->with('labels')->get()->filter($isKnowledge)->values();
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
