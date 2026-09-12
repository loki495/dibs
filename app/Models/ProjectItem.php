<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProjectItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $planned_on
 * @property Carbon|null $due_on
 */
class ProjectItem extends Model
{
    /** @use HasFactory<ProjectItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'planned_on' => 'date', 'due_on' => 'date', 'raw_fields_json' => 'array', 'remote_updated_at' => 'datetime', 'last_synced_at' => 'datetime', 'is_available' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<GitHubProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(GitHubProject::class, 'project_id');
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }

    /** @return BelongsTo<ProjectFieldOption, $this> */
    public function statusOption(): BelongsTo
    {
        return $this->belongsTo(ProjectFieldOption::class, 'status_option_id');
    }

    /** @return BelongsTo<ProjectFieldOption, $this> */
    public function groupOption(): BelongsTo
    {
        return $this->belongsTo(ProjectFieldOption::class, 'group_option_id');
    }

    /** @return BelongsTo<ProjectFieldOption, $this> */
    public function priorityOption(): BelongsTo
    {
        return $this->belongsTo(ProjectFieldOption::class, 'priority_option_id');
    }
}
