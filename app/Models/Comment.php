<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon|null $remote_created_at */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    /** A comment recorded when an issue was closed or completed, carrying its note (the comment body), reason and references. */
    public const string KIND_CLOSING = 'closing';

    protected $guarded = [];

    /** @param  Builder<Comment>  $query */
    public function scopeClosing(Builder $query): void
    {
        $query->where('kind', self::KIND_CLOSING);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['remote_created_at' => 'datetime', 'remote_updated_at' => 'datetime', 'last_synced_at' => 'datetime', 'is_available' => 'boolean', 'last_seen_at' => 'datetime', 'references' => 'array'];
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }
}
