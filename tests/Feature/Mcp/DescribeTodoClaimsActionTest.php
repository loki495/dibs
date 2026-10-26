<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\DescribeTodoClaims;
use App\Exceptions\TodoValidationException;
use App\Models\Issue;

it('returns claim status in input order including duplicates and individual errors', function (): void {
    $held = Issue::factory()->create();
    $free = Issue::factory()->create();
    $removed = Issue::factory()->create(['is_available' => false]);
    $claim = app(ClaimTaskForAgent::class)->handle($held, 'batch-reader', getmypid(), 30);

    $result = app(DescribeTodoClaims::class)->handle([$free->id, $held->id, 999_999, $removed->id, $held->id]);

    expect(array_column($result['items'], 'id'))->toBe([$free->id, $held->id, 999_999, $removed->id, $held->id])
        ->and($result['items'][0])->toBe(['id' => $free->id, 'claimed' => false, 'claim' => null])
        ->and($result['items'][1]['claim']['isCurrentlyAlive'])->toBeTrue()
        ->and($result['items'][1]['claim']['agentName'])->toBe('batch-reader')
        ->and($result['items'][2]['error']['code'])->toBe('not_found')
        ->and($result['items'][3]['error']['code'])->toBe('unavailable')
        ->and($result['items'][4])->toBe($result['items'][1])
        ->and(json_encode($result))->not->toContain($claim['capability_token']);
});

it('accepts the maximum claim batch size', function (): void {
    $issue = Issue::factory()->create();

    expect(app(DescribeTodoClaims::class)->handle(array_fill(0, 100, $issue->id))['items'])->toHaveCount(100);
});

it('rejects empty or oversized claim batches at the action boundary', function (array $ids): void {
    expect(fn () => app(DescribeTodoClaims::class)->handle($ids))->toThrow(TodoValidationException::class, 'Choose between 1 and 100');
})->with([
    'empty' => [[]],
    'oversized' => [range(1, 101)],
]);
