<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\DescribeTodoClaim;
use App\Models\Issue;

it('returns null when the task has no live claim', function (): void {
    $issue = Issue::factory()->create();

    expect(app(DescribeTodoClaim::class)->handle($issue->id))->toBeNull();
});

it('describes a live claim without exposing the capability token', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    $description = app(DescribeTodoClaim::class)->handle($issue->id);

    expect($description)->not->toBeNull()
        ->and($description['agentName'])->toBe('codex')
        ->and($description['pid'])->toBe(getmypid())
        ->and($description['isVerifiedLive'])->toBeTrue()
        ->and($description)->not->toHaveKey('capabilityToken')
        ->and(json_encode($description))->not->toContain('token');
});

it('returns null once the claim is released', function (): void {
    $issue = Issue::factory()->create();
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    $result['claim']->update(['released_at' => now()]);

    expect(app(DescribeTodoClaim::class)->handle($issue->id))->toBeNull();
});
