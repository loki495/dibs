<?php

declare(strict_types=1);

use App\Actions\SyncGitHub;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    config(['github.token' => 'test-token']);
});

it('reports success after a clean sync', function (): void {
    $sync = Mockery::mock(SyncGitHub::class);
    $sync->shouldReceive('handle')->once()->with('test-token', false);
    app()->instance(SyncGitHub::class, $sync);

    $exitCode = Artisan::call('todo:sync');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('imported successfully');
});

it('passes --comments through to the sync', function (): void {
    $sync = Mockery::mock(SyncGitHub::class);
    $sync->shouldReceive('handle')->once()->with('test-token', true);
    app()->instance(SyncGitHub::class, $sync);

    Artisan::call('todo:sync', ['--comments' => true]);
});

it('reports a clean error instead of crashing when the sync fails', function (): void {
    $sync = Mockery::mock(SyncGitHub::class);
    $sync->shouldReceive('handle')->once()->andThrow(new GitHubSyncException('GitHub HTTP 429'));
    app()->instance(SyncGitHub::class, $sync);

    $exitCode = Artisan::call('todo:sync');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('GitHub HTTP 429');
});
