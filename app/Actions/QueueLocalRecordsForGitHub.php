<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;

/**
 * Queues every record created while the instance was local-only, so the push queue mirrors it once
 * GitHub is configured: the same pushes the original writes would have queued had mirroring been on.
 * Runs inside ApplyGitHubSnapshot's transaction, after the import reconciled same-named labels.
 * Areas (GitHub Projects) only ever come from GitHub, so there is no Project membership to queue.
 */
class QueueLocalRecordsForGitHub
{
    public function __construct(private readonly EnqueueGitHubPush $enqueue) {}

    public function handle(GitHubRepository $repository): void
    {
        $labels = Label::query()->where('repository_id', $repository->id)->whereNull('github_node_id')->where('is_available', true)->orderBy('id')->get();
        foreach ($labels as $label) {
            $this->enqueue->handle('create_label', 'label', $label->id, ['name' => $label->name, 'color' => $label->color, 'description' => $label->description], 'label:create:'.$label->id);
        }

        $issues = Issue::query()->where('repository_id', $repository->id)->whereNull('github_node_id')->where('is_available', true)->with('labels')->orderBy('id')->get();
        foreach ($issues as $issue) {
            $this->enqueue->handle('create_issue', 'issue', $issue->id, ['title' => $issue->title, 'body' => $issue->body], 'issue:create:'.$issue->id);
            if ($issue->labels->isNotEmpty()) {
                $this->enqueue->handle('add_issue_labels', 'issue', $issue->id, ['label_ids' => $issue->labels->pluck('id')->all()], 'issue:labels:'.$issue->id);
            }
            // A parent deleted locally is not queued, so linking to it could never be delivered.
            if ($issue->parent_issue_id !== null && $issues->contains('id', $issue->parent_issue_id)) {
                $this->enqueue->handle('set_issue_parent', 'issue', $issue->id, ['parent_issue_id' => $issue->parent_issue_id], 'issue:parent:'.$issue->id);
            }
            if ($issue->state === 'CLOSED') {
                $this->enqueue->handle('close_issue', 'issue', $issue->id, ['stateReason' => $issue->state_reason], 'issue:close:'.$issue->id);
            }
        }

        $comments = Comment::query()->whereIn('issue_id', $issues->pluck('id'))->whereNull('github_node_id')->where('is_available', true)->orderBy('id')->pluck('id');
        foreach ($comments as $commentId) {
            $this->enqueue->handle('create_comment', 'comment', $commentId, [], 'comment:create:'.$commentId);
        }
    }
}
