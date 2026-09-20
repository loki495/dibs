<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Models\Issue;
use Illuminate\Support\Facades\Artisan;

it('releases a live claim and prints the result as json', function (): void {
    $issue = Issue::factory()->create();
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 5);

    $exitCode = Artisan::call('todo:agent:release', [
        'issue' => (string) $issue->id, '--pid' => (string) posix_getppid(), '--token' => $result['capability_token'],
    ]);
    $output = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($output['claim_id'])->toBe($result['claim']->id)
        ->and($output['released_at'])->not->toBeNull();
});

it('rejects a missing --pid without crashing', function (): void {
    $issue = Issue::factory()->create();

    $exitCode = Artisan::call('todo:agent:release', ['issue' => (string) $issue->id, '--token' => 'x']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('numeric --pid');
});

it('rejects a missing --token without crashing', function (): void {
    $issue = Issue::factory()->create();

    $exitCode = Artisan::call('todo:agent:release', ['issue' => (string) $issue->id, '--pid' => (string) posix_getppid()]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('--token');
});

it('surfaces a domain exception for a wrong token as a clean error', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 5);

    $exitCode = Artisan::call('todo:agent:release', [
        'issue' => (string) $issue->id, '--pid' => (string) posix_getppid(), '--token' => 'wrong-token',
    ]);

    expect($exitCode)->toBe(1);
});
