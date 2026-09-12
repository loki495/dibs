<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GetIssueDetails;
use Illuminate\Console\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;

class AgentShowTaskCommand extends Command
{
    protected $signature = 'todo:agent:show {issue : Local Todo issue ID}';

    protected $description = 'Read one Todo task as JSON for a host-local agent';

    public function handle(GetIssueDetails $details): int
    {
        $id = filter_var($this->argument('issue'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException('issue must be a positive local Todo ID.');
        }
        $result = $details->handle($id);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
