<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GitHubMutationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitHubMutation extends Model
{
    /** @use HasFactory<GitHubMutationFactory> */
    use HasFactory;

    protected $table = 'github_mutations';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['intent_json' => 'array', 'requires_reconciliation' => 'boolean', 'confirmed_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }
}
