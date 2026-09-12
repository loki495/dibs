<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProjectFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectField extends Model
{
    /** @use HasFactory<ProjectFieldFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['configuration_json' => 'array', 'remote_updated_at' => 'datetime', 'last_synced_at' => 'datetime', 'is_available' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<GitHubProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(GitHubProject::class, 'project_id');
    }

    /** @return HasMany<ProjectFieldOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ProjectFieldOption::class, 'project_field_id')->orderBy('position');
    }
}
