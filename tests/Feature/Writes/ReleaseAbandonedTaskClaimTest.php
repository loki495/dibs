<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\ReleaseAbandonedTaskClaim;
use App\Models\Issue;
use App\Models\TaskClaim;

it('releases a live claim without needing its capability token', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    $released = app(ReleaseAbandonedTaskClaim::class)->handle($issue->id);

    expect($released->released_at)->not->toBeNull()
        ->and(TaskClaim::query()->sole()->released_at)->not->toBeNull();
});

it('rejects releasing a task with no live claim', function (): void {
    $issue = Issue::factory()->create();

    expect(fn () => app(ReleaseAbandonedTaskClaim::class)->handle($issue->id))
        ->toThrow(DomainException::class, 'no live claim');
});

it('rejects releasing a claim that is already released', function (): void {
    $issue = Issue::factory()->create();
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    $result['claim']->update(['released_at' => now()]);

    expect(fn () => app(ReleaseAbandonedTaskClaim::class)->handle($issue->id))
        ->toThrow(DomainException::class, 'no live claim');
});
