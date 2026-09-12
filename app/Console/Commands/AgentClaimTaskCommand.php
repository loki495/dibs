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
    protected $signature = 'todo:agent:claim {issue : Local Todo issue ID} {--agent= : Agent name} {--session= : Stable local session key} {--minutes=30 : Claim duration, 1 to 480}';

    protected $description = 'Claim a Todo task for one host-local agent session as JSON';

    public function handle(ClaimTaskForAgent $claim): int
    {
        $issue = Issue::query()->where('is_available', true)->find($this->argument('issue'));
        if (! $issue instanceof Issue) {
            $this->error('A current local Todo issue ID is required.');

            return self::FAILURE;
        }
        try {
            $result = $claim->handle($issue, (string) $this->option('agent'), (string) $this->option('session'), (int) $this->option('minutes'));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['claim_id' => $result->id, 'issue_id' => $result->issue_id, 'expires_at' => Carbon::parse($result->expires_at)->toAtomString()], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
