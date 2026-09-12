<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ClaimTaskForAgent;
use App\Models\Issue;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AgentClaimTaskCommand extends Command
{
    protected $signature = 'todo:agent:claim {issue : Local Todo issue ID} {--agent= : Agent name} {--pid= : The calling agent\'s own OS process ID} {--minutes=30 : Claim duration, 1 to 480}';

    protected $description = 'Claim a Todo task for one host-local agent session as JSON';

    public function handle(ClaimTaskForAgent $claim): int
    {
        $issue = Issue::query()->where('is_available', true)->find($this->argument('issue'));
        $pid = $this->option('pid');
        if (! $issue instanceof Issue || $pid === null || ! ctype_digit($pid)) {
            $this->error('A current local Todo issue ID and the calling agent\'s own numeric --pid are required.');

            return self::FAILURE;
        }
        try {
            $result = $claim->handle($issue, (string) $this->option('agent'), (int) $pid, (int) $this->option('minutes'));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode([
            'claim_id' => $result['claim']->id,
            'issue_id' => $result['claim']->issue_id,
            'expires_at' => Carbon::parse($result['claim']->expires_at)->toAtomString(),
            'capability_token' => $result['capability_token'],
            'is_verified_live' => $result['is_verified_live'],
        ], JSON_PRETTY_PRINT));
        if (! $result['is_verified_live']) {
            $this->comment('Note: process liveness could not be verified in this environment (weaker-assurance claim).');
        }

        return self::SUCCESS;
    }
}
