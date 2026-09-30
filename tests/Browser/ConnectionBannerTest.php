<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

const BANNER_TEXT = 'Lost contact with the server';

function signInToWorkspace(): mixed
{
    User::factory()->create(['email' => 'owner@example.com', 'password' => Hash::make('a-strong-password')]);
    Issue::factory()->create(['title' => 'First task', 'state' => 'OPEN']);
    Issue::factory()->create(['title' => 'Second task', 'state' => 'OPEN']);

    return visit('/login')->fill('email', 'owner@example.com')->fill('password', 'a-strong-password')
        ->click('button[type="submit"]')->assertPathIs('/');
}

// Replaces fetch for Livewire update requests only: 'fail' rejects like a lost connection, 'hang' never
// answers (but still rejects when the request is aborted), 'restore' puts the real fetch back.
function breakLivewireRequests(mixed $page, string $mode): void
{
    $page->script(<<<JS
        (() => {
            window.__realFetch ??= window.fetch.bind(window);
            const mode = '{$mode}';
            if (mode === 'restore') { window.fetch = window.__realFetch; return; }
            window.fetch = (input, init) => {
                if (! String(input?.url ?? input).includes('/livewire')) return window.__realFetch(input, init);
                if (mode === 'fail') return Promise.reject(new TypeError('Load failed'));
                return new Promise((resolve, reject) => init?.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError'))));
            };
        })()
        JS);
}

it('stays quiet while requests succeed', function (): void {
    $page = signInToWorkspace();

    $page->click('First task')->assertSee('Discussion & history')->assertDontSee(BANNER_TEXT);
});

it('tells the user when a Livewire request fails instead of silently doing nothing', function (): void {
    $page = signInToWorkspace();
    breakLivewireRequests($page, 'fail');

    $page->click('First task')->wait(1)->assertSee(BANNER_TEXT)->assertSee('Reload')->assertDontSee('Discussion & history');
});

it('gives up on a hung request after the configured timeout and lets later taps through again', function (): void {
    config(['dibs.livewire_request_timeout_seconds' => 1]);
    $page = signInToWorkspace();
    breakLivewireRequests($page, 'hang');

    $page->click('First task')->wait(2.5)->assertSee(BANNER_TEXT);

    breakLivewireRequests($page, 'restore');
    $page->click('Second task')->wait(1)->assertSee('Discussion & history')->assertDontSee(BANNER_TEXT);
});
