<?php

declare(strict_types=1);

/**
 * Only the guard clause is covered here -- the actual build (migrate:fresh + seed) issues a
 * SQLite VACUUM, which cannot run inside RefreshDatabase's wrapping transaction. Same
 * limitation as ResetDemoDataCommand's original design; see docs/architecture.md and
 * docs/demo-hosting.md. This command is run manually by a human, never on a schedule, so the
 * risk profile is materially lower than the shared-database design that limitation was first
 * documented for.
 */
it('refuses to run when the template path is not configured', function (): void {
    config(['dibs.demo_db_template_path' => '']);

    $this->artisan('demo:build-template')
        ->assertFailed()
        ->expectsOutputToContain('dibs.demo_db_template_path is not configured');
});
