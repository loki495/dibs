<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Support\Collection;

class DescribeGitHubPushQueue
{
    /** @return array{pending: int, failed: int, needsAttention: int, pushed: int, actionable: int} */
    public function counts(): array
    {
        $pending = GitHubPushQueueItem::query()->where('status', 'pending')->count();
        $failed = GitHubPushQueueItem::query()->where('status', 'failed')->count();
        $needsAttention = GitHubPushQueueItem::query()->where('status', 'needs_attention')->count();
        $pushed = GitHubPushQueueItem::query()->where('status', 'pushed')->count();

        return ['pending' => $pending, 'failed' => $failed, 'needsAttention' => $needsAttention, 'pushed' => $pushed, 'actionable' => $failed + $needsAttention];
    }

    /**
     * Human-readable description of each row's target, keyed by queue row id, so the
     * queue page can show "dotfiles: glibc missing..." instead of "issue #35".
     *
     * @param  Collection<int, GitHubPushQueueItem>  $items
     * @return array<int, string>
     */
    public function describeTargets(Collection $items): array
    {
        $byType = $items->groupBy('target_type');
        $issues = Issue::query()->whereIn('id', $byType->get('issue', collect())->pluck('target_id')->filter()->unique())->get()->keyBy('id');
        $labels = Label::query()->whereIn('id', $byType->get('label', collect())->pluck('target_id')->filter()->unique())->get()->keyBy('id');
        $options = ProjectFieldOption::query()->with('field')->whereIn('id', $byType->get('project_field_option', collect())->pluck('target_id')->filter()->unique())->get()->keyBy('id');
        $projectItems = ProjectItem::query()->with(['issue', 'project'])->whereIn('id', $byType->get('project_item', collect())->pluck('target_id')->filter()->unique())->get()->keyBy('id');
        $comments = Comment::query()->with('issue')->whereIn('id', $byType->get('comment', collect())->pluck('target_id')->filter()->unique())->get()->keyBy('id');

        $descriptions = [];
        foreach ($items as $item) {
            $descriptions[$item->id] = match ($item->target_type) {
                'issue' => $this->describeIssue($issues->get($item->target_id)),
                'label' => $this->describeLabel($labels->get($item->target_id)),
                'project_field_option' => $this->describeOption($options->get($item->target_id)),
                'project_item' => $this->describeProjectItem($projectItems->get($item->target_id)),
                'comment' => $this->describeComment($comments->get($item->target_id)),
                default => $item->target_type.' #'.$item->target_id,
            };
        }

        return $descriptions;
    }

    private function describeIssue(?Issue $issue): string
    {
        if (! $issue instanceof Issue) {
            return 'Task (no longer exists locally)';
        }

        return ($issue->github_number !== null ? '#'.$issue->github_number.' ' : '(not yet synced) ').$issue->title;
    }

    private function describeLabel(?Label $label): string
    {
        return $label instanceof Label ? 'Label "'.$label->name.'"' : 'Label (no longer exists locally)';
    }

    private function describeOption(?ProjectFieldOption $option): string
    {
        if (! $option instanceof ProjectFieldOption) {
            return 'Project field option (no longer exists locally)';
        }
        $kind = ucfirst((string) ($option->field->semantic_key ?? 'field'));

        return $kind.' option "'.$option->name.'"';
    }

    private function describeProjectItem(?ProjectItem $item): string
    {
        if (! $item instanceof ProjectItem) {
            return 'Project membership (no longer exists locally)';
        }

        return '"'.$item->issue->title.'" in '.$item->project->title;
    }

    private function describeComment(?Comment $comment): string
    {
        if (! $comment instanceof Comment) {
            return 'Comment (no longer exists locally)';
        }

        return 'Comment on "'.$comment->issue->title.'"';
    }
}
