<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use Illuminate\Support\Str;

class GetIssueDetails
{
    /** @return array<string, mixed> */
    public function handle(int $id): array
    {
        $issue = Issue::query()->where('is_available', true)->with([
            'repository', 'labels' => fn ($query) => $query->where('is_available', true),
            'projectItems' => fn ($query) => $query->where('is_available', true)->whereNull('archived_at')->whereHas('project', fn ($project) => $project->where('is_available', true)),
            'projectItems.project', 'projectItems.statusOption', 'projectItems.groupOption', 'projectItems.priorityOption',
            'comments' => fn ($query) => $query->where('is_available', true)->orderBy('remote_created_at'),
        ])->findOrFail($id);

        return ['issue' => $issue, 'body' => $this->renderMarkdown($issue->body ?? ''),
            'comments' => $issue->comments->map(fn ($comment): array => ['id' => $comment->id, 'author' => $comment->author_login,
                'date' => $comment->remote_created_at?->timezone(config('todo.timezone'))->format('M j, Y'), 'body' => $this->renderMarkdown($comment->body)])];
    }

    private function renderMarkdown(string $markdown): string
    {
        return Str::markdown(strip_tags($markdown), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
