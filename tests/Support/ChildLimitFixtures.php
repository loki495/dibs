<?php

declare(strict_types=1);

use App\Actions\ResolveActiveRepository;
use App\Models\GitHubRepository;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Model;

/**
 * Bulk-insert direct children under a parent without model events or per-row repositories.
 *
 * @param  array<string, mixed>  $overrides  columns applied to every inserted child (state, is_available, is_temporary)
 */
function insertChildren(Issue $parent, int $count, array $overrides = []): void
{
    static $sequence = 5_000_000;
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $sequence++;
        $rows[] = [
            'repository_id' => $parent->repository_id,
            'github_node_id' => ($overrides['is_temporary'] ?? false) ? null : 'I_bulk_'.$sequence,
            'github_number' => ($overrides['is_temporary'] ?? false) ? null : $sequence,
            'title' => 'Bulk child '.$sequence,
            'state' => 'OPEN',
            'parent_issue_id' => $parent->id,
            'sibling_position' => $i + 1,
            'is_available' => true,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }
    foreach (array_chunk($rows, 50) as $chunk) {
        Issue::query()->insert($chunk);
    }
}

function childCount(Issue $parent): int
{
    return Issue::query()->where('parent_issue_id', $parent->id)->count();
}

/** Switch to local-only mode (no imported repository) and return a fresh parent in the local repository. */
function localOnlyParent(string $title = 'Crowded parent'): Model
{
    config(['github.owner' => '', 'github.repository' => '']);
    GitHubRepository::query()->delete();
    $local = app(ResolveActiveRepository::class)->local();

    return Issue::factory()->create(['repository_id' => $local->id, 'title' => $title]);
}
