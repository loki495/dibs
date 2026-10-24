<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Adds and removes explicit links from one issue to other issues, or to one comment of another issue.
 * Duplicates and removals of links that do not exist are ignored; a missing, unavailable or self target,
 * or a comment that does not belong to its issue, is a validation error and nothing is changed.
 */
class SyncIssueLinks
{
    public function __construct(private readonly ActivityRecorder $recorder) {}

    /**
     * @param  list<array{issueId: int, commentId?: ?int}>  $add
     * @param  list<array{issueId: int, commentId?: ?int}>  $remove
     */
    public function handle(Issue $from, array $add, array $remove): void
    {
        if ($add === [] && $remove === []) {
            return;
        }

        DB::transaction(function () use ($from, $add, $remove): void {
            $this->apply($from, $add, $remove);
        });
    }

    /**
     * @param  list<array{issueId: int, commentId?: ?int}>  $add
     * @param  list<array{issueId: int, commentId?: ?int}>  $remove
     */
    private function apply(Issue $from, array $add, array $remove): void
    {
        $addKeys = $this->keys($add);
        $removeKeys = $this->keys($remove);
        if (array_intersect(array_keys($addKeys), array_keys($removeKeys)) !== []) {
            throw new TodoValidationException('A link cannot be both added and removed in one revision.');
        }
        $this->assertTargets($from, $addKeys);

        $added = [];
        foreach ($addKeys as $link) {
            $existing = IssueLink::query()->where('from_issue_id', $from->id)->where('to_issue_id', $link['issueId'])
                ->where('to_comment_id', $link['commentId'])->exists();
            if (! $existing) {
                IssueLink::create(['from_issue_id' => $from->id, 'to_issue_id' => $link['issueId'], 'to_comment_id' => $link['commentId']]);
                $added[] = $this->label($link);
            }
        }

        $removed = [];
        foreach ($removeKeys as $link) {
            $count = IssueLink::query()->where('from_issue_id', $from->id)->where('to_issue_id', $link['issueId'])
                ->where('to_comment_id', $link['commentId'])->delete();
            if ($count > 0) {
                $removed[] = $this->label($link);
            }
        }

        if ($added !== [] || $removed !== []) {
            $this->recorder->change('SyncIssueLinks', $from, 'Changed links', ['links' => ['from' => $removed === [] ? null : 'removed '.implode(', ', $removed), 'to' => $added === [] ? null : 'added '.implode(', ', $added)]]);
        }
    }

    /**
     * @param  list<array{issueId: int, commentId?: ?int}>  $links
     * @return array<string, array{issueId: int, commentId: ?int}>
     */
    private function keys(array $links): array
    {
        $keys = [];
        foreach ($links as $link) {
            $commentId = $link['commentId'] ?? null;
            $keys[$link['issueId'].':'.($commentId ?? '')] = ['issueId' => $link['issueId'], 'commentId' => $commentId];
        }

        return $keys;
    }

    /** @param  array<string, array{issueId: int, commentId: ?int}>  $links */
    private function assertTargets(Issue $from, array $links): void
    {
        foreach ($links as $link) {
            if ($link['issueId'] === $from->id) {
                throw new TodoValidationException('An issue cannot link to itself.');
            }
            if (! Issue::query()->where('is_available', true)->whereKey($link['issueId'])->exists()) {
                throw new TodoValidationException("Cannot link to issue {$link['issueId']}: it does not exist or is no longer available.");
            }
            if ($link['commentId'] !== null && ! Comment::query()->where('is_available', true)->whereKey($link['commentId'])->where('issue_id', $link['issueId'])->exists()) {
                throw new TodoValidationException("Cannot link to comment {$link['commentId']}: it does not exist, is unavailable, or does not belong to issue {$link['issueId']}.");
            }
        }
    }

    /** @param  array{issueId: int, commentId: ?int}  $link */
    private function label(array $link): string
    {
        return $link['commentId'] === null ? "issue {$link['issueId']}" : "issue {$link['issueId']} comment {$link['commentId']}";
    }
}
