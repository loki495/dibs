<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expires_at
 * @property Carbon|null $released_at
 * @property string|null $end_reason
 * @property list<string>|null $paths
 * @property bool|null $liveness_alive
 * @property Carbon|null $liveness_checked_at
 */
class TaskClaim extends Model
{
    public const END_DONE = 'done';

    public const END_RELEASED = 'released';

    /** Lease ran out and the next claimer took over. Recorded by the taker: a dying session cannot record its own idle or quota end. */
    public const END_STALE = 'stale';

    /** Verified process was gone when the next claimer took over. */
    public const END_DEAD = 'dead';

    /** A human released it from the workspace UI. */
    public const END_ABANDONED = 'abandoned';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'released_at' => 'datetime', 'liveness_alive' => 'boolean', 'liveness_checked_at' => 'datetime', 'paths' => 'array'];
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
