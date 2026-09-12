<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProjectFieldOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectFieldOption extends Model
{
    /** @use HasFactory<ProjectFieldOptionFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return BelongsTo<ProjectField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(ProjectField::class, 'project_field_id');
    }

    /** @return HasMany<ProjectItem, $this> */
    public function statusItems(): HasMany
    {
        return $this->hasMany(ProjectItem::class, 'status_option_id');
    }

    /** @return HasMany<ProjectItem, $this> */
    public function groupItems(): HasMany
    {
        return $this->hasMany(ProjectItem::class, 'group_option_id');
    }
}
