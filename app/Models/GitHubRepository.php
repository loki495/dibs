<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GitHubRepositoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GitHubRepository extends Model
{
    /** @use HasFactory<GitHubRepositoryFactory> */
    use HasFactory;

    protected $table = 'repositories';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_private' => 'boolean', 'remote_updated_at' => 'datetime', 'last_synced_at' => 'datetime', 'is_available' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    /** @return HasMany<Issue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'repository_id');
    }

    /** @return HasMany<Label, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class, 'repository_id');
    }
}
