<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateTodoIssue;
use App\Actions\GetIssueDetails;
use App\Exceptions\TodoValidationException;
use Illuminate\Console\Command;

class AgentCreateTaskCommand extends Command
{
    protected $signature = 'todo:agent:create
        {title : Task title}
        {--body= : Canonical body/description}
        {--area= : A GitHub Project id}
        {--parent= : Nest under an existing local issue id}
        {--group= : A Group option id; must belong to --area}
        {--priority= : A Priority option id; must belong to --area}
        {--label=* : Existing label ids to attach (repeatable)}
        {--new-group= : Create or reuse a Group by name within --area}
        {--new-label= : Create or reuse a label by name}
        {--idempotency-key= : Retrying the same key returns the original result}';

    protected $description = 'Create a Todo task, plan, or knowledge record locally for a host-local agent as JSON';

    public function handle(CreateTodoIssue $create, GetIssueDetails $details): int
    {
        try {
            $issue = $create->handle(
                title: $this->argument('title'),
                body: $this->option('body'),
                area: $this->option('area') !== null ? (int) $this->option('area') : null,
                parentId: $this->option('parent') !== null ? (int) $this->option('parent') : null,
                groupId: $this->option('group') !== null ? (int) $this->option('group') : null,
                priorityId: $this->option('priority') !== null ? (int) $this->option('priority') : null,
                labelIds: array_map(intval(...), $this->option('label')),
                newGroupName: $this->option('new-group'),
                newLabelName: $this->option('new-label'),
                idempotencyKey: $this->option('idempotency-key'),
            );
        } catch (TodoValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($details->handle($issue->id), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
