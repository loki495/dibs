<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\HeartbeatTaskClaim;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AgentHeartbeatTaskCommand extends Command
{
    protected $signature = 'todo:agent:heartbeat {issue : Local Todo issue ID} {--pid= : The calling agent\'s own OS process ID} {--token= : The capability token returned by todo:agent:claim} {--minutes=30 : New claim duration, 1 to 480}';

    protected $description = 'Renew a live host-local agent task claim as JSON';

    public function handle(HeartbeatTaskClaim $heartbeat): int
    {
        $pid = $this->option('pid');
        $token = (string) $this->option('token');
        if ($pid === null || ! ctype_digit($pid) || $token === '') {
            $this->error('The calling agent\'s own numeric --pid and the claim\'s --token are required.');

            return self::FAILURE;
        }
        try {
            $claim = $heartbeat->handle((int) $this->argument('issue'), (int) $pid, $token, (int) $this->option('minutes'));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['claim_id' => $claim->id, 'expires_at' => Carbon::parse($claim->expires_at)->toAtomString()], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
