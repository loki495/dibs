<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function signedInWithTasks(): mixed
{
    User::factory()->create(['email' => 'owner@example.com', 'password' => Hash::make('a-strong-password')]);
    Issue::factory()->create(['title' => 'First task', 'state' => 'OPEN']);

    return visit('/login')->fill('email', 'owner@example.com')->fill('password', 'a-strong-password')
        ->click('button[type="submit"]')->assertPathIs('/');
}

// 'slow' answers Livewire update requests after two seconds; 'fail' rejects them.
function delayLivewireRequests(mixed $page, string $mode): void
{
    $page->script(<<<JS
        (() => {
            const real = window.fetch.bind(window);
            window.fetch = (input, init) => {
                if (! String(input?.url ?? input).includes('/livewire')) return real(input, init);
                if ('{$mode}' === 'fail') return Promise.reject(new TypeError('Load failed'));
                return new Promise(resolve => setTimeout(() => resolve(real(input, init)), 2000));
            };
        })()
        JS);
}

function indicatorState(mixed $page): array
{
    return $page->script(<<<'JS'
        ({
            bar: document.querySelector('[data-loading-bar]').matches(':popover-open'),
            dimmed: 'loading' in document.documentElement.dataset,
            busy: [...document.querySelectorAll('[data-busy]')].some(element => element.getAttribute('aria-busy') === 'true'),
        })
        JS);
}

it('shows the loading bar and dims the list while a request is slow, then clears both', function (): void {
    $page = signedInWithTasks();
    delayLivewireRequests($page, 'slow');

    $page->click('First task')->wait(0.8);
    expect(indicatorState($page))->toBe(['bar' => true, 'dimmed' => true, 'busy' => true]);

    $page->wait(2.5)->assertSee('Discussion & history');
    expect(indicatorState($page))->toBe(['bar' => false, 'dimmed' => false, 'busy' => false]);
});

it('does not flash the indicator for a request that answers quickly', function (): void {
    $page = signedInWithTasks();

    $page->click('First task')->wait(1)->assertSee('Discussion & history');

    expect(indicatorState($page))->toBe(['bar' => false, 'dimmed' => false, 'busy' => false]);
});

it('clears the indicator when a request fails instead of leaving it stuck', function (): void {
    $page = signedInWithTasks();
    delayLivewireRequests($page, 'fail');

    $page->click('First task')->wait(1);

    expect(indicatorState($page))->toBe(['bar' => false, 'dimmed' => false, 'busy' => false]);
});
