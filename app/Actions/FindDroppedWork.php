<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Models\TaskClaim;
use App\Support\IssueFilters;

/**
 * Open tasks someone started but left: the latest claim lapsed without being released or completed
 * (idle session, quota end, crash) or a human released it as abandoned, and nobody holds it now.
 * A session cannot record its own death, so a lapsed lease is the evidence, not a stored reason.
 * Temporary tasks are included: finished ones are deleted, so any that remain were dropped.
 * Scope it like todo_list: by Area (project), Group, labels, or a parent issue (its children or the whole tree).
 */
class FindDroppedWork
{
    public const MAX_LIMIT = 100;

    public function __construct(private readonly ApplyIssueFilters $applyFilters) {}

    /** @return array{items: list<array<string, mixed>>, unresolved?: array<string, list<string>>} */
    public function handle(?string $pathPrefix = null, int $limit = 50, ?IssueFilters $filters = null): array
    {
        $unresolved = [];
        $latest = TaskClaim::query()->selectRaw('max(id)')->groupBy('issue_id');
        $claims = TaskClaim::query()
            ->whereIn('id', $latest)
            ->whereHas('issue', function ($q) use ($filters, &$unresolved): void {
                $q->where('state', 'OPEN')->where('is_available', true);
                if ($filters instanceof IssueFilters) {
                    $unresolved = array_filter($this->applyFilters->handle($q, $filters));
                }
            })
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('released_at')->where('expires_at', '<=', now()))
                ->orWhere('end_reason', TaskClaim::END_ABANDONED))
            ->with(['issue', 'agentSession'])
            ->orderByDesc('expires_at')
            ->get();

        $items = [];
        foreach ($claims as $claim) {
            $paths = $claim->paths ?? [];
            if ($pathPrefix !== null && ! collect($paths)->contains(fn (string $path): bool => str_starts_with($path, $pathPrefix) || str_starts_with($pathPrefix, $path))) {
                continue;
            }
            $issue = $claim->issue;
            if (! $issue instanceof Issue) {
                continue;
            }
            $items[] = [
                'issueId' => $issue->id,
                'title' => $issue->title,
                'temporary' => $issue->is_temporary,
                'how' => $claim->released_at === null ? 'lapsed' : TaskClaim::END_ABANDONED,
                'agentName' => $claim->agentSession->agent_name,
                'lastSeenAt' => $claim->agentSession->last_seen_at->toAtomString(),
                'endedAt' => ($claim->released_at ?? $claim->expires_at)->toAtomString(),
                'paths' => $paths,
            ];
            if (count($items) >= min($limit, self::MAX_LIMIT)) {
                break;
            }
        }

        return ['items' => $items, ...($unresolved === [] ? [] : ['unresolved' => $unresolved])];
    }
}
