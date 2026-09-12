<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GitHubPushQueueItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed> $payload */
class GitHubPushQueueItem extends Model
{
    /** @use HasFactory<GitHubPushQueueItemFactory> */
    use HasFactory;

    protected $table = 'github_push_queue';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'attempted_at' => 'datetime', 'pushed_at' => 'datetime'];
    }
}
