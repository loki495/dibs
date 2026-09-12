<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CompleteTodoTask;
use App\Exceptions\TodoRecordUnavailableException;
use DomainException;
use Illuminate\Console\Command;

class AgentCompleteTaskCommand extends Command
{
    protected $signature = 'todo:agent:complete {issue : Local Todo issue ID} {--pid= : The calling agent\'s own OS process ID} {--token= : The capability token returned by todo:agent:claim} {--summary= : An optional result summary, posted as a comment}';

    protected $description = 'Complete a claimed host-local agent task as JSON';

    public function handle(CompleteTodoTask $complete): int
    {
        $pid = $this->option('pid');
        $token = (string) $this->option('token');
        if ($pid === null || ! ctype_digit($pid) || $token === '') {
            $this->error('The calling agent\'s own numeric --pid and the claim\'s --token are required.');

            return self::FAILURE;
        }
        try {
            $issue = $complete->handle((int) $this->argument('issue'), (int) $pid, $token, $this->option('summary'));
        } catch (DomainException|TodoRecordUnavailableException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['issue_id' => $issue->id, 'state' => $issue->state, 'url' => $issue->url], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
