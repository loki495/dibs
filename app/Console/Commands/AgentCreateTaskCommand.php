<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateTodoIssue;
use App\Actions\CreateTodoIssues;
use App\Actions\DescribeTodoIssue;
use App\Actions\ReadTodoBatchFile;
use App\Exceptions\TodoValidationException;
use Illuminate\Console\Command;
use RuntimeException;

class AgentCreateTaskCommand extends Command
{
    protected $signature = 'todo:agent:create
        {title? : Task title; omit when using --tasks-file}
        {--tasks-file= : JSON file containing 1–20 independent task definitions (up to 2 MiB)}
        {--body= : Canonical body/description}
        {--area= : A GitHub Project id; defaults to the parent\'s sole active project}
        {--parent= : Nest under an existing local issue id}
        {--group= : A Group option id in the effective area; defaults to the parent\'s Group}
        {--priority= : A Priority option id in the explicit or inherited area; never inherited}
        {--label=* : Existing label ids to attach (repeatable)}
        {--new-group= : Create or reuse a Group by name in the explicit or inherited area}
        {--new-label= : Create or reuse a label by name}
        {--idempotency-key= : Retrying the same key returns the original result}';

    protected $description = 'Create a Todo task, plan, or knowledge record locally for a host-local agent as JSON';

    public function handle(CreateTodoIssue $create, DescribeTodoIssue $details, CreateTodoIssues $batch, ReadTodoBatchFile $read): int
    {
        try {
            if ($this->option('tasks-file') !== null) {
                $singleOptions = ['body', 'area', 'parent', 'group', 'priority', 'new-group', 'new-label', 'idempotency-key'];
                if ($this->argument('title') !== null || $this->option('label') !== []
                    || array_any($singleOptions, fn (string $option): bool => $this->option($option) !== null)) {
                    throw new TodoValidationException('Provide either --tasks-file or single-task arguments, not both.');
                }
                $result = $batch->handle($read->handle($this->option('tasks-file')));
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

                return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
            }
            if ($this->argument('title') === null) {
                throw new RuntimeException('A title or --tasks-file is required.');
            }
            $issue = $create->handle(
                title: $this->argument('title'),
                body: $this->option('body'),
                area: $this->option('area') !== null ? (int) $this->option('area') : null,
                parentId: $this->option('parent') !== null ? (int) $this->option('parent') : null,
                groupId: $this->option('group') !== null ? (int) $this->option('group') : null,
                priorityId: $this->option('priority') !== null ? (int) $this->option('priority') : null,
                labelIds: array_map(intval(...), $this->option('label')),
                newGroupName: $this->option('new-group'),
                newLabelNames: $this->option('new-label') !== null ? [$this->option('new-label')] : [],
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
