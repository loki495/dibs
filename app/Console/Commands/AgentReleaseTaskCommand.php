<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReleaseTaskClaim;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AgentReleaseTaskCommand extends Command
{
    protected $signature = 'todo:agent:release {issue : Local Todo issue ID} {--session= : Stable local session key}';

    protected $description = 'Release a host-local agent task claim as JSON';

    public function handle(ReleaseTaskClaim $release): int
    {
        try {
            $claim = $release->handle((int) $this->argument('issue'), (string) $this->option('session'));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['claim_id' => $claim->id, 'released_at' => $claim->released_at === null ? null : Carbon::parse($claim->released_at)->toAtomString()], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
