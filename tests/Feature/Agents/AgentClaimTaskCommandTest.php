<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\TaskClaim;
use App\Services\Process\LinuxProcessLiveness;
use Illuminate\Support\Facades\Artisan;

it('claims a task and prints the claim as json', function (): void {
    $issue = Issue::factory()->create();

    $exitCode = Artisan::call('todo:agent:claim', [
        'issue' => (string) $issue->id, '--agent' => 'codex', '--pid' => (string) posix_getppid(),
    ]);
    $output = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($output['issue_id'])->toBe($issue->id)
        ->and(TaskClaim::query()->where('issue_id', $issue->id)->exists())->toBeTrue();
});

it('adds a weaker-assurance note when process liveness cannot be verified in this environment', function (): void {
    app()->instance(LinuxProcessLiveness::class, new LinuxProcessLiveness(sys_get_temp_dir().'/nonexistent-proc-'.uniqid()));
    $issue = Issue::factory()->create();

    Artisan::call('todo:agent:claim', ['issue' => (string) $issue->id, '--agent' => 'codex', '--pid' => (string) posix_getppid()]);

    expect(Artisan::output())->toContain('weaker-assurance claim');
});

it('rejects a missing --pid without crashing', function (): void {
    $issue = Issue::factory()->create();

    $exitCode = Artisan::call('todo:agent:claim', ['issue' => (string) $issue->id, '--agent' => 'codex']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('numeric --pid');
});

it('rejects a non-numeric --pid without crashing', function (): void {
    $issue = Issue::factory()->create();

    $exitCode = Artisan::call('todo:agent:claim', ['issue' => (string) $issue->id, '--pid' => 'not-a-pid']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('numeric --pid');
});

it('rejects an unavailable issue without crashing', function (): void {
    $exitCode = Artisan::call('todo:agent:claim', ['issue' => '999999', '--pid' => (string) posix_getppid()]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('current local Todo issue ID');
});

it('surfaces a domain exception (e.g. already claimed) as a clean error', function (): void {
    $issue = Issue::factory()->create();
    Artisan::call('todo:agent:claim', ['issue' => (string) $issue->id, '--pid' => (string) posix_getppid()]);

    $exitCode = Artisan::call('todo:agent:claim', ['issue' => (string) $issue->id, '--pid' => '999999']);

    expect($exitCode)->toBe(1);
});
