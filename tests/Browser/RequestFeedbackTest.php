<?php

declare(strict_types=1);

use App\Models\GitHubProject;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
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

function filterChipState(mixed $page, string $label): array
{
    return $page->script("(() => { const chip = [...document.querySelectorAll('[data-optimistic-mode=\"toggle\"]')].find(element => element.textContent.trim() === '{$label}'); return { pending: chip.dataset.pending ?? null, pressed: chip.getAttribute('aria-pressed'), background: getComputedStyle(chip).backgroundColor }; })()");
}

function signedInWithLabels(): mixed
{
    User::factory()->create(['email' => 'owner@example.com', 'password' => Hash::make('a-strong-password')]);
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    Label::factory()->for($repository, 'repository')->create(['name' => 'urgent']);
    Label::factory()->for($repository, 'repository')->create(['name' => 'blocked']);
    Issue::factory()->create(['title' => 'First task', 'state' => 'OPEN']);

    return visit('/login')->fill('email', 'owner@example.com')->fill('password', 'a-strong-password')
        ->click('button[type="submit"]')->assertPathIs('/');
}

it('highlights a label filter the instant it is tapped, before the server answers, and keeps it once it does', function (): void {
    $page = signedInWithLabels();
    $before = filterChipState($page, 'urgent');
    delayLivewireRequests($page, 'slow');

    $page->click('[data-optimistic-mode="toggle"]:has-text("urgent")')->wait(0.4);
    $during = filterChipState($page, 'urgent');
    expect($before['pending'])->toBeNull()->and($before['pressed'])->toBe('false')
        ->and($during['pending'])->toBe('on')->and($during['pressed'])->toBe('false')
        ->and($during['background'])->not->toBe($before['background']);

    $page->wait(2.5);
    $after = filterChipState($page, 'urgent');
    expect($after['pending'])->toBeNull()->and($after['pressed'])->toBe('true')
        ->and($after['background'])->toBe($during['background']);
});

it('unhighlights an active label filter the instant it is tapped again', function (): void {
    $page = signedInWithLabels();
    $page->click('[data-optimistic-mode="toggle"]:has-text("urgent")')->wait(1);
    $selected = filterChipState($page, 'urgent');
    delayLivewireRequests($page, 'slow');

    $page->click('[data-optimistic-mode="toggle"]:has-text("urgent")')->wait(0.4);
    $during = filterChipState($page, 'urgent');
    expect($selected['pressed'])->toBe('true')
        ->and($during['pending'])->toBe('off')->and($during['pressed'])->toBe('true')
        ->and($during['background'])->not->toBe($selected['background']);

    $page->wait(2.5);
    expect(filterChipState($page, 'urgent')['pressed'])->toBe('false');
});

it('drops the optimistic highlight when the request fails, showing the real unchanged state', function (): void {
    $page = signedInWithLabels();
    $before = filterChipState($page, 'urgent');
    delayLivewireRequests($page, 'fail');

    $page->click('[data-optimistic-mode="toggle"]:has-text("urgent")')->wait(1);

    $after = filterChipState($page, 'urgent');
    expect($after['pending'])->toBeNull()->and($after['pressed'])->toBe($before['pressed']);
});

it('switches the highlighted area pill at once on a phone, and unhighlights the previous one', function (): void {
    User::factory()->create(['email' => 'owner@example.com', 'password' => Hash::make('a-strong-password')]);
    GitHubProject::factory()->create(['title' => 'Alpha Area']);
    GitHubProject::factory()->create(['title' => 'Beta Area']);
    $page = visit('/login')->resize(390, 664)->fill('email', 'owner@example.com')->fill('password', 'a-strong-password')
        ->click('button[type="submit"]')->assertPathIs('/');
    delayLivewireRequests($page, 'slow');

    $page->click('[aria-label="Workspace area"] button:has-text("Beta Area")')->wait(0.4);

    $pills = $page->script("[...document.querySelectorAll('[aria-label=\"Workspace area\"] [data-optimistic]')].map(pill => ({ text: pill.textContent.trim().replace(/\\s+/g, ' '), pending: pill.dataset.pending ?? null, pressed: pill.getAttribute('aria-pressed') }))");
    $beta = collect($pills)->first(fn (array $pill): bool => str_contains($pill['text'], 'Beta Area'));
    $all = collect($pills)->first(fn (array $pill): bool => str_starts_with($pill['text'], 'All'));

    expect($beta['pending'])->toBe('on')->and($beta['pressed'])->toBe('false')
        ->and($all['pressed'])->toBe('true')->and($all['pending'])->toBeNull();

    $inGroup = $page->script("document.querySelector('[aria-label=\"Workspace area\"]').hasAttribute('data-pending-group')");
    expect($inGroup)->toBeTrue();

    $page->wait(2.5);
    expect($page->script("document.querySelector('[aria-label=\"Workspace area\"]').hasAttribute('data-pending-group')"))->toBeFalse();
});
