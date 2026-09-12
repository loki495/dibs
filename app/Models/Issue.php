<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['remote_updated_at' => 'datetime', 'last_synced_at' => 'datetime', 'is_available' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<GitHubRepository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitHubRepository::class, 'repository_id');
    }

    /** @return BelongsTo<Issue, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_issue_id');
    }

    /** @return HasMany<Issue, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_issue_id')->orderBy('sibling_position');
    }

    /** @return HasMany<ProjectItem, $this> */
    public function projectItems(): HasMany
    {
        return $this->hasMany(ProjectItem::class, 'issue_id');
    }

    /** @return BelongsToMany<Label, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'issue_label');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'issue_id');
    }
}
