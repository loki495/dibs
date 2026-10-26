<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Issue;

class DescribeTodoClaims
{
    public const MAX_IDS = 100;

    public function __construct(private readonly DescribeTodoClaim $describe) {}

    /** @param list<int> $ids
     * @return array{items: list<array<string, mixed>>}
     */
    public function handle(array $ids): array
    {
        if ($ids === [] || count($ids) > self::MAX_IDS) {
            throw new TodoValidationException('Choose between 1 and '.self::MAX_IDS.' issue ids.');
        }
        $issues = Issue::query()->whereIn('id', $ids)->get(['id', 'is_available'])->keyBy('id');
        $items = [];
        $cache = [];
        foreach ($ids as $id) {
            if (isset($cache[$id])) {
                $items[] = $cache[$id];

                continue;
            }
            $issue = $issues->get($id);
            if (! $issue instanceof Issue) {
                $item = ['id' => $id, 'error' => ['code' => 'not_found', 'message' => 'No Todo issue with this local id.']];
            } elseif (! $issue->is_available) {
                $item = ['id' => $id, 'error' => ['code' => 'unavailable', 'message' => 'This Todo issue is no longer available.']];
            } else {
                $claim = $this->describe->handle($id);
                $item = ['id' => $id, 'claimed' => $claim !== null, 'claim' => $claim];
            }
            $cache[$id] = $item;
            $items[] = $item;
        }

        return ['items' => $items];
    }
}
