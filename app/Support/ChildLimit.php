<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\TodoValidationException;
use App\Models\Issue;

/**
 * Soft and hard cap on the direct children of one parent, which GitHub limits to 100 sub-issues.
 * Only mirrored instances are limited; temporary parents never are.
 */
class ChildLimit
{
    /**
     * Check adding children to a parent. Returns a warning when the resulting count reaches the
     * warning threshold, null when it is fine.
     *
     * @throws TodoValidationException when the resulting count exceeds the cap
     */
    public function check(Issue $parent, int $adding = 1): ?string
    {
        if ($parent->is_temporary || ! GitHubMirror::mirrored()) {
            return null;
        }

        $existing = Issue::query()->where('parent_issue_id', $parent->id)
            ->where('is_available', true)->where('is_temporary', false)->count();

        return $this->evaluate($parent->title, $existing + $adding);
    }

    /** Same decision for a parent that does not exist yet (a plan being scaffolded). */
    public function checkNew(string $title, int $children): ?string
    {
        return GitHubMirror::mirrored() ? $this->evaluate($title, $children) : null;
    }

    public function key(Issue $parent): string
    {
        return 'child-limit:'.$parent->id;
    }

    private function evaluate(string $title, int $total): ?string
    {
        $cap = (int) config('dibs.child_limit.refuse_above');
        if ($total > $cap) {
            throw new TodoValidationException("\"{$title}\" would have {$total} sub-tasks; the limit is {$cap}. Group the work under another parent.");
        }

        if ($total >= (int) config('dibs.child_limit.warn_at')) {
            return "\"{$title}\" now has {$total} sub-tasks; GitHub allows at most {$cap}.";
        }

        return null;
    }
}
