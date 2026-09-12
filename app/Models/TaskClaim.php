<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskClaim extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'released_at' => 'datetime'];
    }

    /** @return BelongsTo<AgentSession, $this> */
    public function agentSession(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class);
    }
}
