<?php

declare(strict_types=1);

use App\Models\ChangeLog;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\McpCallLog;
use App\Models\User;

it('ships the loading bar and hands the configured delay to the browser', function (): void {
    config(['dibs.loading_indicator_delay_ms' => 320]);

    $this->actingAs(User::factory()->create())->get('/')
        ->assertOk()
        ->assertSee('data-loading-bar', false)
        ->assertSee('<meta name="dibs-loading-delay" content="320">', false);
});

it('defaults to a 150 ms delay', function (): void {
    expect(config('dibs.loading_indicator_delay_ms'))->toBe(150);
});

it('marks the lists that dim while a request is in flight', function (): void {
    Issue::factory()->create(['title' => 'A task']);
    GitHubPushQueueItem::factory()->create();
    ChangeLog::factory()->create();
    McpCallLog::factory()->create();
    $this->actingAs(User::factory()->create());

    foreach (['/', '/push-queue', '/activity'] as $path) {
        $this->get($path)->assertOk()->assertSee('data-busy', false);
    }
});

it('ships the loading bar on the sign-in page too, for the page load after signing in', function (): void {
    $this->get('/login')->assertOk()->assertSee('data-loading-bar', false);
});
