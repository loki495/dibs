<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\HeartbeatTaskClaim;
use App\Models\Issue;

it('rejects a renewal length outside 1 to 480 minutes', function (int $minutes): void {
    $issue = Issue::factory()->create();
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 5);

    expect(fn () => app(HeartbeatTaskClaim::class)->handle($issue->id, posix_getppid(), $result['capability_token'], $minutes))
        ->toThrow(DomainException::class, '1 to 480 minutes');
})->with([0, -1, 481]);
