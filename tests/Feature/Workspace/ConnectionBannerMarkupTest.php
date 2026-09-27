<?php

declare(strict_types=1);

use App\Models\User;

it('ships a hidden reconnect banner with a Reload button on every page', function (): void {
    $this->actingAs(User::factory()->create())->get('/')
        ->assertOk()
        ->assertSee('data-connection-banner', false)
        ->assertSee('data-reload', false)
        ->assertSee('Lost contact with the server', false);
});

it('ships the banner on the sign-in page too, before anyone is signed in', function (): void {
    $this->get('/login')->assertOk()->assertSee('data-connection-banner', false);
});

it('hands the configured request timeout to the browser', function (): void {
    config(['dibs.livewire_request_timeout_seconds' => 7]);

    $this->actingAs(User::factory()->create())->get('/')
        ->assertSee('<meta name="dibs-request-timeout" content="7">', false);
});

it('defaults to a 20 second timeout and passes 0 through so the timeout can be disabled', function (): void {
    expect(config('dibs.livewire_request_timeout_seconds'))->toBe(20);

    config(['dibs.livewire_request_timeout_seconds' => 0]);

    $this->actingAs(User::factory()->create())->get('/')
        ->assertSee('<meta name="dibs-request-timeout" content="0">', false);
});
