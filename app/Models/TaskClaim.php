<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expires_at
 * @property Carbon|null $released_at
 * @property bool|null $liveness_alive
 * @property Carbon|null $liveness_checked_at
 */
class TaskClaim extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'released_at' => 'datetime', 'liveness_alive' => 'boolean', 'liveness_checked_at' => 'datetime'];
    }

    /** @return BelongsTo<AgentSession, $this> */
    public function agentSession(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class);
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}
