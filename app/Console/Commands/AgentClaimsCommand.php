<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\FindClaimPathOverlaps;
use DomainException;
use Illuminate\Console\Command;

class AgentClaimsCommand extends Command
{
    protected $signature = 'todo:agent:claims {--path=* : Absolute file or directory to check (repeatable)} {--except-session= : Skip claims whose agent name ends with ":<this>" (the caller\'s own conversation id)} {--held-by= : List every live claim held by this conversation id instead (used by the stop hook)}';

    protected $description = 'List live claims whose paths overlap the given paths, or that a conversation holds, as JSON (used by the edit-overlap and stop hooks)';

    public function handle(FindClaimPathOverlaps $overlaps): int
    {
        if ($this->option('held-by')) {
            $this->line(json_encode($overlaps->heldBy((string) $this->option('held-by')), JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        try {
            $found = $overlaps->handle(array_values((array) $this->option('path')), exceptSession: $this->option('except-session') ?: null);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($found, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
