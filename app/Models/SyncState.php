<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SyncStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $resource_key
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $retry_after
 * @property string|null $last_error
 * @property int $completed_reconciliation_generation
 */
class SyncState extends Model
{
    /** @use HasFactory<SyncStateFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_success_at' => 'datetime', 'last_attempt_at' => 'datetime', 'retry_after' => 'datetime', 'completed_reconciliation_generation' => 'integer'];
    }
}
