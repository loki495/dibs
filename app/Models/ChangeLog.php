<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChangeLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property array<string, array{from: mixed, to: mixed}>|null $changes
 * @property int|null $subject_id
 * @property Carbon|null $created_at
 */
class ChangeLog extends Model
{
    /** @use HasFactory<ChangeLogFactory> */
    use HasFactory;

    public const SOURCE_UI = 'ui';

    public const SOURCE_MCP = 'mcp';

    public const SOURCE_CLI = 'cli';

    public const SOURCE_SYSTEM = 'system';

    public const CATEGORY_CHANGE = 'change';

    public const CATEGORY_AUTH = 'auth';

    public const CATEGORY_SYNC = 'sync';

    public const CATEGORY_QUEUE = 'queue';

    public const ACTOR_USER = 'user';

    public const ACTOR_AGENT = 'agent';

    public const ACTOR_SYSTEM = 'system';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changes' => 'array', 'subject_id' => 'integer', 'created_at' => 'datetime'];
    }
}
