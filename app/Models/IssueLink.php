<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit link from one issue to another issue, or to one comment of it. The kind of the target
 * (rule, lesson, research, decision, guide, or an ordinary task) is read from its labels, never stored.
 */
class IssueLink extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Issue, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'from_issue_id');
    }

    /** @return BelongsTo<Issue, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'to_issue_id');
    }

    /** @return BelongsTo<Comment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'to_comment_id');
    }
}
