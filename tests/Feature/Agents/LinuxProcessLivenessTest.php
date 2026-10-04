<?php

declare(strict_types=1);

use App\Services\Process\LinuxProcessLiveness;
use Illuminate\Support\Carbon;

it('finds the real start time of a genuinely running process', function (): void {
    $liveness = new LinuxProcessLiveness;

    $startedAt = $liveness->startedAt(getmypid());

    expect($startedAt)->toBeInstanceOf(Carbon::class);
    expect($startedAt->lessThanOrEqualTo(now()))->toBeTrue();
});

it('returns null for a process id that does not exist', function (): void {
    $liveness = new LinuxProcessLiveness;

    expect($liveness->startedAt(999_999_999))->toBeNull();
});

it('confirms liveness when the reported start time matches reality', function (): void {
    $liveness = new LinuxProcessLiveness;
    $pid = getmypid();
    $actual = $liveness->startedAt($pid);

    expect($liveness->isAlive($pid, $actual))->toBeTrue();
});

it('detects PID reuse when the stored start time no longer matches the live process', function (): void {
    $liveness = new LinuxProcessLiveness;

    // Simulates: this pid used to belong to a different process that started at this (wrong) time —
    // the current process holding the pid now started at a different, real time.
    $staleExpectedStart = now()->subDays(30);

    expect($liveness->isAlive(getmypid(), $staleExpectedStart))->toBeFalse();
});

it('treats a nonexistent process as not alive regardless of the expected start time', function (): void {
    $liveness = new LinuxProcessLiveness;

    expect($liveness->isAlive(999_999_999, now()))->toBeFalse();
});

it('reports unverifiable when /proc is not readable in this environment', function (): void {
    $liveness = new LinuxProcessLiveness(sys_get_temp_dir().'/nonexistent-proc-'.uniqid());

    expect($liveness->isVerifiable())->toBeFalse();
    expect($liveness->startedAt(getmypid()))->toBeNull();
});

it('reports verifiable when the real /proc is readable', function (): void {
    expect((new LinuxProcessLiveness)->isVerifiable())->toBeTrue();
});

it('returns null when the stat file has no closing paren after the process name', function (): void {
    $procPath = sys_get_temp_dir().'/fake-proc-'.uniqid();
    mkdir($procPath.'/123', recursive: true);
    file_put_contents($procPath.'/stat', "btime 1000000000\n");
    file_put_contents($procPath.'/123/stat', '123 (no-closing-paren-here');

    $liveness = new LinuxProcessLiveness($procPath);

    expect($liveness->startedAt(123))->toBeNull();
});

it('returns null when the stat file has too few fields to contain a start time', function (): void {
    $procPath = sys_get_temp_dir().'/fake-proc-'.uniqid();
    mkdir($procPath.'/123', recursive: true);
    file_put_contents($procPath.'/stat', "btime 1000000000\n");
    file_put_contents($procPath.'/123/stat', '123 (proc) S 1 1 1');

    $liveness = new LinuxProcessLiveness($procPath);

    expect($liveness->startedAt(123))->toBeNull();
});

it('returns null boot time when /proc/stat has no btime line', function (): void {
    $procPath = sys_get_temp_dir().'/fake-proc-'.uniqid();
    mkdir($procPath.'/123', recursive: true);
    file_put_contents($procPath.'/stat', "cpu 0 0 0 0\n");
    file_put_contents($procPath.'/123/stat', '123 (proc) S '.str_repeat('1 ', 20));

    $liveness = new LinuxProcessLiveness($procPath);

    expect($liveness->startedAt(123))->toBeNull();
});

it('reports unverifiable and knows no start time when disabled, even with a readable /proc', function (): void {
    $liveness = new LinuxProcessLiveness(enabled: false);

    expect($liveness->isVerifiable())->toBeFalse()
        ->and($liveness->startedAt(getmypid()))->toBeNull();
});

it('reports currentlyAlive as null when it cannot check, never false', function (): void {
    $actual = (new LinuxProcessLiveness)->startedAt(getmypid());

    expect((new LinuxProcessLiveness(enabled: false))->currentlyAlive(getmypid(), $actual))->toBeNull()
        ->and((new LinuxProcessLiveness(sys_get_temp_dir().'/nonexistent-proc-'.uniqid()))->currentlyAlive(getmypid(), $actual))->toBeNull();
});

it('reports currentlyAlive as true or false when it can check', function (): void {
    $liveness = new LinuxProcessLiveness;
    $actual = $liveness->startedAt(getmypid());

    expect($liveness->currentlyAlive(getmypid(), $actual))->toBeTrue()
        ->and($liveness->currentlyAlive(getmypid(), now()->subDays(30)))->toBeFalse()
        ->and($liveness->currentlyAlive(999_999_999, now()))->toBeFalse();
});

it('resolves from the container with DIBS_PROCESS_LIVENESS applied', function (bool $enabled): void {
    config(['dibs.process_liveness' => $enabled]);

    expect(app(LinuxProcessLiveness::class)->isVerifiable())->toBe($enabled);
})->with([true, false]);
