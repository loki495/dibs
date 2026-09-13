<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A singleton settings row for the natural-language capture feature (plan #53).
 * Opt-in by default (`enabled` starts false) -- capture never runs an agent CLI
 * on Andres's behalf until this is explicitly turned on.
 *
 * @property array<int, string> $enabled_agents
 */
class CaptureSetting extends Model
{
    /** Order encodes priority: the first entry is tried first. */
    public const DEFAULT_AGENT_ORDER = ['claude', 'codex', 'opencode', 'agy'];

    /** @var list<string> */
    public const KNOWN_AGENTS = ['claude', 'codex', 'agy', 'opencode'];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'enabled_agents' => 'array'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], ['enabled' => false, 'enabled_agents' => self::DEFAULT_AGENT_ORDER]);
    }
}
