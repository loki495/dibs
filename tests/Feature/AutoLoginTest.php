<?php

declare(strict_types=1);

use App\Models\User;
use Tests\TestCase;

/**
 * The opt-in auto-login on a normal (non-demo) deployment: it only ever signs in an existing account named
 * by auto_login_email, and only for a trusted request.
 */
beforeEach(function (): void {
    /** @var TestCase $this */
    $this->withoutVite();
    User::factory()->create(['email' => 'owner@example.com']);
});

it('does nothing by default', function (): void {
    /** @var TestCase $this */
    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50'])->assertRedirect('/login');
});

it('signs in the configured account for a LAN request when opted in', function (): void {
    /** @var TestCase $this */
    config(['dibs.auto_login_lan' => true, 'dibs.auto_login_email' => 'owner@example.com']);

    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50'])->assertOk();

    $this->assertAuthenticated();
    expect(auth()->user()?->email)->toBe('owner@example.com');
});

it('does not sign in without an account configured, or for a missing account', function (): void {
    /** @var TestCase $this */
    config(['dibs.auto_login_lan' => true]);
    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50'])->assertRedirect('/login');

    config(['dibs.auto_login_email' => 'nobody@example.com']);
    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50'])->assertRedirect('/login');

    $this->assertGuest();
});

it('does not sign in a public address or a Cloudflare-routed request', function (): void {
    /** @var TestCase $this */
    config(['dibs.auto_login_lan' => true, 'dibs.auto_login_email' => 'owner@example.com']);

    $this->call('GET', '/', server: ['REMOTE_ADDR' => '8.8.8.8'])->assertRedirect('/login');
    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4'])
        ->assertRedirect('/login');
});

it('never signs in a request carrying Cloudflare headers naming the account, with LAN auto-login on or off', function (bool $lan): void {
    /** @var TestCase $this */
    config(['dibs.auto_login_lan' => $lan, 'dibs.auto_login_email' => 'owner@example.com']);

    $this->call('GET', '/', server: [
        'REMOTE_ADDR' => '192.168.1.50',
        'HTTP_CF_RAY' => 'abc123',
        'HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL' => 'owner@example.com',
    ])->assertRedirect('/login');

    $this->assertGuest();
})->with([[true], [false]]);
