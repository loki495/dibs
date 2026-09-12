<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\TaskClaim;
use Illuminate\Support\Facades\Artisan;

it('claims a task via the CLI and prints a usable capability token', function (): void {
    $issue = Issue::factory()->create();

    $this->artisan('todo:agent:claim', ['issue' => $issue->id, '--agent' => 'codex', '--pid' => (string) getmypid()])
        ->assertExitCode(0);

    $claim = TaskClaim::query()->sole();
    expect($claim->issue_id)->toBe($issue->id)->and($claim->agentSession->is_verified_live)->toBeTrue();
});

it('refuses to claim without a numeric --pid', function (): void {
    $issue = Issue::factory()->create();

    $this->artisan('todo:agent:claim', ['issue' => $issue->id, '--agent' => 'codex'])->assertExitCode(1);

    expect(TaskClaim::query()->count())->toBe(0);
});

it('completes a claim, heartbeat, release lifecycle end to end via the CLI', function (): void {
    $issue = Issue::factory()->create();
    Artisan::call('todo:agent:claim', ['issue' => $issue->id, '--agent' => 'codex', '--pid' => (string) getmypid()]);
    $output = json_decode(Artisan::output(), true);
    $token = $output['capability_token'];

    Artisan::call('todo:agent:heartbeat', ['issue' => $issue->id, '--pid' => (string) getmypid(), '--token' => $token, '--minutes' => 45]);
    expect(Artisan::output())->toContain('claim_id');

    $this->artisan('todo:agent:release', ['issue' => $issue->id, '--pid' => (string) getmypid(), '--token' => $token])
        ->assertExitCode(0);
    expect(TaskClaim::query()->sole()->released_at)->not->toBeNull();
});

it('refuses release and heartbeat without the matching capability token', function (): void {
    $issue = Issue::factory()->create();
    Artisan::call('todo:agent:claim', ['issue' => $issue->id, '--agent' => 'codex', '--pid' => (string) getmypid()]);

    $this->artisan('todo:agent:release', ['issue' => $issue->id, '--pid' => (string) getmypid(), '--token' => 'wrong'])
        ->assertExitCode(1);
    $this->artisan('todo:agent:heartbeat', ['issue' => $issue->id, '--pid' => (string) getmypid(), '--token' => 'wrong'])
        ->assertExitCode(1);
    expect(TaskClaim::query()->sole()->released_at)->toBeNull();
});
