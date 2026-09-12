<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int|null $pid
 * @property Carbon|null $process_started_at
 * @property bool $is_verified_live
 * @property Carbon $last_seen_at
 * @property Carbon $expires_at
 */
class AgentSession extends Model
{
    protected $guarded = [];

    /** Never serialize the token hash — it's a credential, even hashed. */
    protected $hidden = ['capability_token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'expires_at' => 'datetime', 'process_started_at' => 'datetime', 'is_verified_live' => 'boolean'];
    }
}
