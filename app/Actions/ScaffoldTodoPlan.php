<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

class ScaffoldTodoPlan
{
    public function __construct(
        private readonly CreateTodoIssue $create,
        private readonly ResolveIdempotentWrite $idempotent,
    ) {}

    /**
     * Create a plan issue and its initial child task issues atomically: if any
     * child fails validation, nothing is created — never a plan with a partial
     * set of children.
     *
     * @param  list<int>  $labelIds
     * @param  list<array{title: string, body?: ?string, groupId?: ?int, priorityId?: ?int, labelIds?: list<int>}>  $children
     */
    public function handle(
        string $title,
        ?string $body = null,
        ?int $area = null,
        ?int $groupId = null,
        ?int $priorityId = null,
        array $labelIds = [],
        ?string $newLabelName = null,
        array $children = [],
        ?string $idempotencyKey = null,
    ): Issue {
        foreach ($children as $index => $child) {
            if (trim($child['title']) === '') {
                throw new TodoValidationException("Child task at position {$index} needs a title.");
            }
        }

        $scaffold = fn (): Issue => DB::transaction(function () use ($title, $body, $area, $groupId, $priorityId, $labelIds, $newLabelName, $children): Issue {
            $plan = $this->create->handle(title: $title, body: $body, area: $area, groupId: $groupId, priorityId: $priorityId, labelIds: $labelIds, newLabelNames: $newLabelName !== null ? [$newLabelName] : []);
            foreach ($children as $child) {
                $this->create->handle(
                    title: $child['title'],
                    body: $child['body'] ?? null,
                    area: $area,
                    parentId: $plan->id,
                    groupId: $child['groupId'] ?? null,
                    priorityId: $child['priorityId'] ?? null,
                    labelIds: $child['labelIds'] ?? [],
                );
            }

            return $plan->refresh()->load('children');
        });

        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $scaffold();
        }

        return $this->idempotent->handle(
            $idempotencyKey,
            'issue',
            fn (int $id) => Issue::query()->with('children')->find($id),
            $scaffold,
        );
    }
}
