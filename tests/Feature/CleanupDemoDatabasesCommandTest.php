<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/dibs-demo-cleanup-test-'.uniqid();
    mkdir($this->dir, recursive: true);
    config(['dibs.demo_db_storage_path' => $this->dir]);
});

afterEach(function (): void {
    array_map(unlink(...), glob("{$this->dir}/*") ?: []);
    @rmdir($this->dir);
});

it('does nothing when the storage directory does not exist yet', function (): void {
    config(['dibs.demo_db_storage_path' => "{$this->dir}/does-not-exist"]);

    $this->artisan('demo:cleanup')->assertSuccessful();
});

it('deletes files older than the retention window and keeps recent ones', function (): void {
    $stale = "{$this->dir}/stale.sqlite";
    $fresh = "{$this->dir}/fresh.sqlite";
    touch($stale, time() - (25 * 3600));
    touch($fresh, time() - 60);

    $this->artisan('demo:cleanup')->assertSuccessful();

    expect(file_exists($stale))->toBeFalse()
        ->and(file_exists($fresh))->toBeTrue();
});

it('respects a custom --hours retention window', function (): void {
    $file = "{$this->dir}/two-hours-old.sqlite";
    touch($file, time() - (2 * 3600));

    $this->artisan('demo:cleanup', ['--hours' => 1])->assertSuccessful();

    expect(file_exists($file))->toBeFalse();
});

it('ignores non-sqlite files in the storage directory', function (): void {
    $other = "{$this->dir}/notes.txt";
    touch($other, time() - (25 * 3600));

    $this->artisan('demo:cleanup')->assertSuccessful();

    expect(file_exists($other))->toBeTrue();
});
