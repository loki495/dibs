<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\User;

it('opens export and import from settings and protects a populated workspace on mobile', function (): void {
    $this->actingAs(User::factory()->create());
    Issue::factory()->create();
    $page = visit('/')->resize(390, 844);
    $page->click('button[aria-label="Settings"]:visible')->click('a:visible:has-text("Data export/import")')
        ->assertPathIs('/data-transfer')->assertSee('Download JSON')->assertSee('Validate and preview')
        ->assertSee('This workspace already contains data')->assertNoJavascriptErrors();
});

it('renders export and import in dark mode without browser errors', function (): void {
    $this->actingAs(User::factory()->create());
    $page = visit('/data-transfer');
    $page->script("window.todoTheme.set('dark')");
    $page->assertSee('Export workspace')->assertSee('Import workspace')->assertNoJavascriptErrors();
});
