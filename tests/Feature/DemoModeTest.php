<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;

/**
 * Feature-level test of ResolveDemoDatabase against a real route, not in isolation --
 * this is what a real visitor's first and second requests actually look like.
 */
beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/dibs-demo-feature-test-'.uniqid();
    mkdir($this->tempDir, recursive: true);
    $this->templatePath = "{$this->tempDir}/template.sqlite";
    $this->storagePath = "{$this->tempDir}/demo-dbs";

    touch($this->templatePath);
    config(['database.connections.sqlite_demo_template' => [
        'driver' => 'sqlite', 'database' => $this->templatePath, 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    Artisan::call('migrate', ['--database' => 'sqlite_demo_template', '--force' => true]);

    $this->originalSqlitePath = config('database.connections.sqlite.database');

    config([
        'dibs.demo_mode' => true,
        'dibs.demo_db_template_path' => $this->templatePath,
        'dibs.demo_db_storage_path' => $this->storagePath,
    ]);

    // ResolveDemoDatabase purges the 'sqlite' connection whenever it repoints its database
    // file. RefreshDatabase (active suite-wide, see tests/Pest.php) began this test's
    // transaction on a specific shared in-memory PDO tracked in
    // RefreshDatabaseState::$inMemoryConnections, and expects to roll back *that exact* PDO
    // at teardown -- but by then 'sqlite' resolves to whatever connection the middleware last
    // created instead, so Laravel's own rollback callback rolls back the wrong object, leaves
    // the real shared PDO's transaction dangling open, and marks the database as unmigrated
    // for the next test, corrupting every later test in the suite. Fix it directly: roll back
    // the actual shared PDO ourselves and confirm the migrated flag, in a callback registered
    // after (so it runs after) RefreshDatabase's own. Same gotcha, same fix, as the sibling
    // homie project's equivalent test -- see docs/demo-hosting.md.
    $this->beforeApplicationDestroyed(function (): void {
        $pdo = RefreshDatabaseState::$inMemoryConnections['sqlite'] ?? null;

        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        RefreshDatabaseState::$migrated = true;
    });
});

afterEach(function (): void {
    config(['dibs.demo_mode' => false, 'database.connections.sqlite.database' => $this->originalSqlitePath]);

    array_map(unlink(...), glob("{$this->storagePath}/*") ?: []);
    @rmdir($this->storagePath);
    @unlink($this->templatePath);
    @rmdir($this->tempDir);
});

it('does nothing when demo mode is off', function (): void {
    config(['dibs.demo_mode' => false]);

    $this->get('/login')->assertOk();

    expect(is_dir($this->storagePath))->toBeFalse();
});

it('gives a first-time visitor their own private copy of the template', function (): void {
    $this->get('/login')->assertOk()->assertCookie('demo_instance_id');

    $copies = glob("{$this->storagePath}/*.sqlite") ?: [];
    expect($copies)->toHaveCount(1)
        ->and(filesize($copies[0]))->toBe(filesize($this->templatePath));
});

it('reuses the same copy for a returning visitor instead of creating another one', function (): void {
    $first = $this->get('/login');
    $cookie = $first->getCookie('demo_instance_id');

    $this->withCookie($cookie->getName(), $cookie->getValue())->get('/login')->assertOk();

    expect(glob("{$this->storagePath}/*.sqlite") ?: [])->toHaveCount(1);
});

it('aborts with a clear error when the template is missing', function (): void {
    unlink($this->templatePath);

    $this->get('/login')->assertStatus(500);
});
