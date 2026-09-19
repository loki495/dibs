<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('redirects a guest to the login page in a real browser', function (): void {
    $page = visit('/');

    $page->assertPathIs('/login');
});

it('logs in through the real login form and reaches the workspace', function (): void {
    User::factory()->create([
        'email' => 'owner@example.com',
        'password' => Hash::make('a-strong-password'),
    ]);

    $page = visit('/login');

    $page->fill('email', 'owner@example.com')
        ->fill('password', 'a-strong-password')
        // A plain ->click('Sign in') is ambiguous: the page has both an <h1>Sign in</h1>
        // heading and the submit button with the same text, and GuessLocator's text
        // fallback matches the heading first (its DOM position precedes the button),
        // silently clicking a no-op element instead of submitting the form.
        ->click('button[type="submit"]')
        ->assertPathIs('/')
        ->assertDontSee('Sign in');
});
