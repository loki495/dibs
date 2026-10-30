<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoSeeder;

it('lets a visitor explore the sample agent plan and its linked context', function (int $width, string $theme): void {
    config(['github.owner' => '', 'github.repository' => '']);
    $this->seed(DemoSeeder::class);
    $this->actingAs(User::query()->where('email', 'demo@example.com')->sole());

    $page = visit('/')->resize($width, 844);
    $page->script("window.todoTheme.set('{$theme}')");
    $page->click('Expand all')->click('[aria-label="Open issue Plan: Add notification digests"]')->assertSee('Why keep this in Dibs?')
        ->assertSee('claude:demo:notification-bell')->assertSee('codex:demo:preferences')
        ->assertSee('Rule: Keep notification delivery local and opt-in')
        ->assertNoJavascriptErrors()->screenshot(filename: "demo-plan-{$width}-{$theme}");
})->with([[390, 'light'], [390, 'dark'], [1280, 'light'], [1280, 'dark']]);
