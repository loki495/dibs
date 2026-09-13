<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GetIssueDetails;
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
        {--note= : Optional revision note, posted as a comment}
        {--idempotency-key= : Retrying the same key returns the original result}';

    protected $description = 'Revise a Todo task\'s title/body for a host-local agent as JSON';

    public function handle(ReviseTodoIssue $revise, GetIssueDetails $details): int
    {
        $expectedRevision = $this->option('expected-revision');
        if ($expectedRevision === null || ! ctype_digit((string) $expectedRevision)) {
            $this->error('A numeric --expected-revision (the revision last read for this issue) is required.');

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
