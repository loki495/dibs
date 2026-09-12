<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReleaseTaskClaim;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AgentReleaseTaskCommand extends Command
{
    protected $signature = 'todo:agent:release {issue : Local Todo issue ID} {--pid= : The calling agent\'s own OS process ID} {--token= : The capability token returned by todo:agent:claim}';

    protected $description = 'Release a host-local agent task claim as JSON';

    public function handle(ReleaseTaskClaim $release): int
    {
        $pid = $this->option('pid');
        $token = (string) $this->option('token');
        if ($pid === null || ! ctype_digit($pid) || $token === '') {
            $this->error('The calling agent\'s own numeric --pid and the claim\'s --token are required.');

            return self::FAILURE;
        }
        try {
            $claim = $release->handle((int) $this->argument('issue'), (int) $pid, $token);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['claim_id' => $claim->id, 'released_at' => $claim->released_at === null ? null : Carbon::parse($claim->released_at)->toAtomString()], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
