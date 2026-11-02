<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\ClaimTaskForAgent;
use App\Actions\DescribeWorkOrder;
use App\Mcp\CallerPid;
use App\Mcp\Tools\Concerns\RunsBatch;
use App\Models\Issue;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Claims a task for the calling agent process, so other agents cannot pick up the same work. The caller\'s own OS process is identified automatically — the connecting agent is found from the client-supplied DIBS_CLIENT_PID or the server\'s parent process, so no pid argument is needed. Returns a capability token: keep it in memory and pass it to todo_heartbeat, todo_release, and todo_complete for this same claim. Also returns `workOrder`, the step\'s capped brief (as todo_next), with the full text of each linked rule this agentName has not yet received at its current revision; a rule already received comes as id, title and revision only. After a context reset (compaction, /clear, resume) pass contextReset: true to receive every rule\'s text again. Pass `paths` (absolute files/directories the work will touch) to be told, in `overlaps`, which other live claims cover an overlapping path. A task already claimed by a live process cannot be claimed again. Pass issueIds (1–20) instead of issueId to claim several tasks in one call with shared agentName/minutes/paths: returns items with each claim result or its error, best effort.')]
class ClaimTodoTask extends Tool implements Errable
{
    use RunsBatch;

    protected string $name = 'todo_claim';

    public function handle(Request $request, ClaimTaskForAgent $claim, CallerPid $callerPid, DescribeWorkOrder $workOrder): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'issueId' => ['required_without:issueIds', 'prohibits:issueIds', 'integer', 'min:1'],
            'issueIds' => ['required_without:issueId', 'prohibits:issueId', 'array', 'list', 'min:1', 'max:'.self::MAX_BATCH],
            'issueIds.*' => ['required', 'integer', 'min:1'],
            'agentName' => ['required', 'string', 'max:100'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:480'],
            'contextReset' => ['sometimes', 'boolean'],
            'paths' => ['sometimes', 'array'],
            'paths.*' => ['string'],
        ]);

        $one = fn (int $issueId): array => $this->claimOne($issueId, $arguments, $claim, $callerPid, $workOrder);

        if (isset($arguments['issueIds'])) {
            return $this->runBatch(array_values(array_map(intval(...), $arguments['issueIds'])), fn (int $issueId): array => $one($issueId));
        }

        try {
            return Response::structured($one($arguments['issueId']));
        } catch (DomainException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    private function claimOne(int $issueId, array $arguments, ClaimTaskForAgent $claim, CallerPid $callerPid, DescribeWorkOrder $workOrder): array
    {
        $issue = Issue::query()->where('is_available', true)->find($issueId);
        if (! $issue instanceof Issue) {
            throw new DomainException("Issue {$issueId} was not found or is not available.");
        }

        $result = $claim->handle($issue, $arguments['agentName'], $callerPid->resolve(), $arguments['minutes'] ?? 30, $arguments['paths'] ?? []);

        return [
            'claimId' => $result['claim']->id,
            'issueId' => $result['claim']->issue_id,
            'expiresAt' => Carbon::parse($result['claim']->expires_at)->toAtomString(),
            'capabilityToken' => $result['capability_token'],
            'isVerifiedLive' => $result['is_verified_live'],
            'overlaps' => $result['overlaps'],
            'workOrder' => $workOrder->handle($issue, $arguments['agentName'], $arguments['contextReset'] ?? false),
        ];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issueId' => $schema->integer()->description('The local issue id to claim. Supply exactly one of issueId or issueIds.'),
            'issueIds' => $schema->array()->items($schema->integer()->min(1))->min(1)->max(self::MAX_BATCH)->description('Up to 20 issue ids to claim in one call (shared agentName, minutes, paths). Each result carries its own capabilityToken or error.'),
            'agentName' => $schema->string()->required()->description('A human-readable name for this agent/session.'),
            'minutes' => $schema->integer()->description('Claim duration in minutes, 1 to 480. Defaults to 30.'),
            'contextReset' => $schema->boolean()->description('This agent\'s context was reset (compaction, /clear, resume): forget which rules it already received, so the work order sends their full text again.'),
            'paths' => $schema->array()->items($schema->string())->description('Absolute files or directories (at most 20) this work will touch. Live claims on an overlapping path (same, ancestor or descendant) come back in `overlaps`; a warning only, the claim still succeeds.'),
        ];
    }
}
