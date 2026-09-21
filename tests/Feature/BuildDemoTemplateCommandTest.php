<?php

declare(strict_types=1);

use App\Models\Issue;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/dibs-build-template-test-'.uniqid();
    mkdir($this->tempDir, recursive: true);
    $this->templatePath = "{$this->tempDir}/template.sqlite";
    $this->originalSqlitePath = config('database.connections.sqlite.database');

    // BuildDemoTemplateCommand purges the 'sqlite' connection whenever it repoints its database
    // file - see DemoModeTest.php for the full explanation of why RefreshDatabase's own
    // rollback needs this same correction, or later tests fail re-migrating a database still
    // mid-transaction.
    $this->beforeApplicationDestroyed(function (): void {
        $pdo = RefreshDatabaseState::$inMemoryConnections['sqlite'] ?? null;

        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        RefreshDatabaseState::$migrated = true;
    });
});

afterEach(function (): void {
    config(['database.connections.sqlite.database' => $this->originalSqlitePath]);
    array_map(unlink(...), glob("{$this->tempDir}/*") ?: []);
    @rmdir($this->tempDir);
});

it('refuses to run when the template path is not configured', function (): void {
    config(['dibs.demo_db_template_path' => '']);

    $this->artisan('demo:build-template')
        ->assertFailed()
        ->expectsOutputToContain('dibs.demo_db_template_path is not configured');
});

it('migrates and seeds the demo dataset at the configured path', function (): void {
    config(['dibs.demo_db_template_path' => $this->templatePath]);

    $exitCode = Artisan::call('demo:build-template');

    expect($exitCode)->toBe(0)
        ->and(file_exists($this->templatePath))->toBeTrue();

    $pdo = new PDO('sqlite:'.$this->templatePath);
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='issues'")->fetchAll();

    expect($tables)->not->toBeEmpty();

    config(['database.connections.sqlite.database' => $this->templatePath]);
    DB::purge('sqlite');

    expect(Issue::query()->count())->toBeGreaterThan(0);
});
