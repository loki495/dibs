<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\ReleaseTaskClaim;
use App\Models\Issue;
use App\Models\TaskClaim;

it('claims an available task and renews it for the same session', function (): void {
    $issue = Issue::factory()->create();
    $first = app(ClaimTaskForAgent::class)->handle($issue, 'codex', 'session-a', 15);
    $renewed = app(ClaimTaskForAgent::class)->handle($issue, 'codex', 'session-a', 30);

    expect(TaskClaim::query()->count())->toBe(1)->and($renewed->id)->toBe($first->id)->and($renewed->expires_at)->toBeGreaterThan($first->expires_at);
});

it('rejects a live claim owned by another session and replaces an expired claim', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', 'session-a');
    expect(fn () => app(ClaimTaskForAgent::class)->handle($issue, 'claude', 'session-b'))->toThrow(DomainException::class, 'currently claimed');
    TaskClaim::query()->sole()->update(['expires_at' => now()->subMinute()]);
    $claim = app(ClaimTaskForAgent::class)->handle($issue, 'claude', 'session-b');
    expect($claim->agentSession->session_key)->toBe('session-b');
});

it('releases a claim only for its owning session', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', 'session-a');

    expect(fn () => app(ReleaseTaskClaim::class)->handle($issue->id, 'session-b'))->toThrow(DomainException::class, 'does not hold');
    $released = app(ReleaseTaskClaim::class)->handle($issue->id, 'session-a');
    expect($released->released_at)->not->toBeNull();
});
