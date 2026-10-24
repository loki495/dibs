<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\FindClaimPathOverlaps;
use DomainException;
use Illuminate\Console\Command;

class AgentClaimsCommand extends Command
{
    protected $signature = 'todo:agent:claims {--path=* : Absolute file or directory to check (repeatable)} {--except-session= : Skip claims whose agent name ends with ":<this>" (the caller\'s own conversation id)}';

    protected $description = 'List live claims whose paths overlap the given paths, as JSON (used by the edit-overlap hook)';

    public function handle(FindClaimPathOverlaps $overlaps): int
    {
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
