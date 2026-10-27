<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Support\GitHubMirror;

class EnqueueGitHubPush
{
    /**
     * Record an intended GitHub write for the scheduled push worker to deliver.
     * Retry-safe: calling again with the same idempotency key refreshes a still-pending
     * row's payload instead of duplicating it, and leaves an already-pushed row alone.
     * Local-only (no repository imported from GitHub yet) nothing is queued and null is returned.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $operation, string $targetType, ?int $targetId, array $payload, string $idempotencyKey): ?GitHubPushQueueItem
    {
        if (! GitHubMirror::mirrored()) {
            return null;
        }
        $issue = match ($targetType) {
            'issue' => Issue::find($targetId),
            'comment' => Comment::find($targetId)?->issue,
            'project_item' => ProjectItem::find($targetId)?->issue,
            default => null,
        };
        if ($issue?->is_temporary) {
            return null;
        }
        if ($targetType === 'project_field_option') {
            $option = ProjectFieldOption::find($targetId);
            if ($option && $option->is_temporary_origin && $option->github_option_id === null
                && ! $option->groupItems()->whereHas('issue', fn ($issues) => $issues->where('is_temporary', false))->exists()) {
                return null;
            }
        }
        if ($targetType === 'label') {
            $label = Label::find($targetId);
            if ($label && $label->is_temporary_origin && $label->github_node_id === null
                && ! $label->issues()->where('is_temporary', false)->exists()) {
                return null;
            }
        }
        if (in_array($operation, ['add_issue_labels', 'set_issue_labels'], true)) {
            foreach (Label::query()->whereIn('id', $payload['label_ids'] ?? $payload['add_label_ids'] ?? [])->whereNull('github_node_id')->get() as $label) {
                $this->handle('create_label', 'label', $label->id, ['name' => $label->name, 'color' => $label->color, 'description' => $label->description], 'label:create:'.$label->id);
            }
        }
        if ($operation === 'set_project_item_group' && isset($payload['group_option_id'])) {
            $option = ProjectFieldOption::find($payload['group_option_id']);
            if ($option?->is_temporary_origin) {
                $option->update(['is_temporary_origin' => false]);
            }
            if ($option && $option->github_option_id === null) {
                $this->handle('create_group_option', 'project_field_option', $option->id, ['name' => $option->name, 'color' => $option->color], 'group_option:create:'.$option->id);
            }
        }
        $existing = GitHubPushQueueItem::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing instanceof GitHubPushQueueItem && $existing->status === 'pushed') {
            return $existing;
        }
        if ($existing instanceof GitHubPushQueueItem) {
            // A row brought back from needs_attention starts a fresh retry budget; a still-pending
            // row keeps its backoff.
            $retry = $existing->status === 'pending' ? [] : ['attempts' => 0, 'next_attempt_at' => null];
            $existing->update(['operation' => $operation, 'target_type' => $targetType, 'target_id' => $targetId, 'payload' => $payload, 'status' => 'pending', 'last_error' => null, ...$retry]);

            return $existing->refresh();
        }

        return GitHubPushQueueItem::query()->create([
            'operation' => $operation, 'target_type' => $targetType, 'target_id' => $targetId,
            'payload' => $payload, 'status' => 'pending', 'idempotency_key' => $idempotencyKey,
        ]);
    }
}
