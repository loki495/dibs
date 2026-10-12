<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DescribeTodoIssue;
use App\Actions\ReviseTodoIssue;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use Illuminate\Console\Command;

class AgentUpdateTaskCommand extends Command
{
    protected $signature = 'todo:agent:update
        {issue : Local Todo issue ID}
        {--expected-revision= : The `revision` last read for this issue}
        {--title= : New title}
        {--body= : New canonical body}
        {--parent= : Local issue ID to move this task under}
        {--clear-parent : Detach the task from its parent}
        {--new-group= : Move to the Group with this name in the task\'s area (created if missing)}
        {--clear-group : Remove the task from its Group}
        {--add-label=* : Label name to attach (repeatable); created if missing}
        {--remove-label=* : Label name to detach (repeatable); ignored if the task lacks it}
        {--note= : Optional revision note, posted as a comment}
        {--idempotency-key= : Retrying the same key returns the original result}';

    protected $description = 'Revise a Todo task\'s title/body/Group/parent/labels for a host-local agent as JSON';

    public function handle(ReviseTodoIssue $revise, DescribeTodoIssue $details): int
    {
        $expectedRevision = $this->option('expected-revision');
        if ($expectedRevision === null || ! ctype_digit((string) $expectedRevision)) {
            $this->error('A numeric --expected-revision (the revision last read for this issue) is required.');

            return self::FAILURE;
        }

        $parent = $this->option('parent');
        if ($parent !== null && ! ctype_digit((string) $parent)) {
            $this->error('A numeric --parent (a local issue ID) is required.');

            return self::FAILURE;
        }

        try {
            $issue = $revise->handle(
                id: (int) $this->argument('issue'),
                expectedRevision: (int) $expectedRevision,
                title: $this->option('title'),
                body: $this->option('body'),
                note: $this->option('note'),
                idempotencyKey: $this->option('idempotency-key'),
                addLabels: $this->option('add-label'),
                removeLabels: $this->option('remove-label'),
                parentId: $parent === null ? null : (int) $parent,
                clearParent: (bool) $this->option('clear-parent'),
                newGroupName: $this->option('new-group'),
                clearGroup: (bool) $this->option('clear-group'),
            );
        } catch (TodoStaleRevisionException $exception) {
            $this->line(json_encode(['conflict' => true, 'message' => $exception->getMessage(), 'current' => $details->handle($exception->currentId)], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (TodoRecordNotFoundException|TodoRecordUnavailableException|TodoValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode(['conflict' => false] + $details->handle($issue->id), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
