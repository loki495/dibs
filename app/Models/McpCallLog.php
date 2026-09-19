<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $arguments
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 */
class McpCallLog extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_ERROR = 'error';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_EXCEPTION = 'exception';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'duration_ms' => 'integer', 'created_at' => 'datetime'];
    }
}
