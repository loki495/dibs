<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Support\TrustedAuthors;

class PeekTodoIssues
{
    public function __construct(private readonly TrustedAuthors $trust) {}

    public const MAX_IDS = 100;

    /**
     * Cheap batch check for whether cached records have changed: id, title, revision and state,
     * never the body. `revision` only bumps on ReviseTodoIssue (title/body/Group) — it says nothing
     * about state, labels, comments, claims or Priority, so callers who care about those must compare
     * `state` (or fetch the full record) too. An id with no matching issue is reported under
     * `unresolved` rather than failing the whole call; a deleted issue is still returned, with
     * `available: false`, since "it's gone" is itself the answer a caller checking for change wants.
     *
     * @param  list<int>  $ids
     * @return array{items: list<array{id: int, title: string, revision: int, state: string, available: bool}>, unresolved: list<int>}
     */
    public function handle(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $issues = Issue::query()->whereIn('id', $ids)->get(['id', 'title', 'author_login', 'revision', 'state', 'is_available'])->keyBy('id');

        $items = [];
        $unresolved = [];
        foreach ($ids as $id) {
            $issue = $issues->get($id);
            if (! $issue instanceof Issue) {
                $unresolved[] = $id;

                continue;
            }
            $items[] = [
                'id' => $issue->id, 'title' => $this->trust->title($issue), 'revision' => $issue->revision,
                'state' => $issue->state, 'available' => $issue->is_available,
            ];
        }

        return ['items' => $items, 'unresolved' => $unresolved];
    }
}
