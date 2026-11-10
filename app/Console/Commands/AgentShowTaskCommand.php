<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DescribeCompleteTodoIssue;
use App\Actions\DescribeTodoIssue;
use Illuminate\Console\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;

class AgentShowTaskCommand extends Command
{
    protected $signature = 'todo:agent:show {issue : Local Todo issue ID} {--all-comments : Return all comments in one consistent snapshot}';

    protected $description = 'Read one Todo task as JSON for a host-local agent';

    public function handle(DescribeTodoIssue $details, DescribeCompleteTodoIssue $complete): int
    {
        $id = filter_var($this->argument('issue'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException('issue must be a positive local Todo ID.');
        }
        $result = $this->option('all-comments')
            ? $complete->handle($id)
            : $details->handle($id, withComments: true, commentsPerPage: DescribeTodoIssue::MAX_COMMENTS_PER_PAGE);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
