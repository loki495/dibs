<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GitHubProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GitHubProject extends Model
{
    /** @use HasFactory<GitHubProjectFactory> */
    use HasFactory;

    protected $table = 'projects';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_closed' => 'boolean', 'is_public' => 'boolean', 'remote_updated_at' => 'datetime', 'last_synced_at' => 'datetime', 'is_available' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    /** @return HasMany<ProjectItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ProjectItem::class, 'project_id');
    }

    /** @return HasMany<ProjectField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(ProjectField::class, 'project_id');
    }
}
