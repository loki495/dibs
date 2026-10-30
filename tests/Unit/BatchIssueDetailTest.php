<?php

declare(strict_types=1);

use App\Support\BatchIssueDetail;

it('keeps identity and names omitted fields when even clipped detail cannot fit', function (): void {
    $detail = [
        'id' => fake()->numberBetween(1, 100_000),
        'revision' => 3,
        'state' => 'OPEN',
        'withheld' => false,
        'title' => str_repeat('界😀', 100),
        'body' => str_repeat('界😀', 100),
        'url' => str_repeat('界😀', 100),
    ];

    $result = (new BatchIssueDetail)->fit($detail, 300);

    expect(strlen(json_encode($result, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(300)
        ->and($result['id'])->toBe($detail['id'])
        ->and($result['issue'])->toBe(array_intersect_key($detail, array_flip(['id', 'revision', 'state', 'withheld'])))
        ->and($result['omittedFields'])->toBe(['title', 'body', 'url'])
        ->and($result['truncatedFields'])->toBe([]);
});
