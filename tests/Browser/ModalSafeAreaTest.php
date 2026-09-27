<?php

declare(strict_types=1);

use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// A notched phone can't be emulated, but the popups read the insets through --safe-top/--safe-bottom, so
// overriding those stands in for env(safe-area-inset-*).
function openAtPhoneSize(): mixed
{
    User::factory()->create(['email' => 'owner@example.com', 'password' => Hash::make('a-strong-password')]);
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    Issue::factory()->create(['title' => 'A task', 'state' => 'OPEN']);
    foreach (range(1, 30) as $number) {
        Label::factory()->for($repository, 'repository')->create(['name' => "label number {$number}"]);
    }

    $page = visit('/login')->resize(390, 664)->fill('email', 'owner@example.com')->fill('password', 'a-strong-password')
        ->click('button[type="submit"]')->assertPathIs('/');
    $page->script("document.documentElement.style.setProperty('--safe-top', '59px'); document.documentElement.style.setProperty('--safe-bottom', '34px');");

    return $page;
}

it('keeps the tall Manage labels popup below the status bar and scrollable down to its Close button', function (): void {
    $page = openAtPhoneSize();
    $page->click('button[aria-label="Settings"]:visible')->click('button:visible:has-text("Manage labels")')->wait(1);

    $box = $page->script(<<<'JS'
        (() => {
            const dialog = document.querySelector('dialog[data-modal="manage-labels"]');
            const top = dialog.querySelector('[data-flux-modal-content]').getBoundingClientRect().top;
            dialog.scrollTop = dialog.scrollHeight;
            const close = [...dialog.querySelectorAll('button')].find(button => button.textContent.trim() === 'Close').getBoundingClientRect();
            return { top, closeBottom: close.bottom, viewport: innerHeight };
        })()
        JS);

    expect($box['top'])->toBeGreaterThanOrEqual(59)
        ->and($box['closeBottom'])->toBeLessThanOrEqual($box['viewport'] - 34);
});

it('keeps the Add task popup inside the safe area and scrollable when it is taller than the phone', function (): void {
    $page = openAtPhoneSize();
    $page->click('Add task')->wait(1);

    $box = $page->script(<<<'JS'
        (() => {
            const dialog = document.querySelector('dialog[data-modal="capture-task"]');
            const rect = dialog.getBoundingClientRect();
            return { top: rect.top, bottom: rect.bottom, viewport: innerHeight, scrolls: dialog.scrollHeight > dialog.clientHeight };
        })()
        JS);

    expect($box['top'])->toBeGreaterThanOrEqual(59)
        ->and($box['bottom'])->toBeLessThanOrEqual($box['viewport'] - 34)
        ->and($box['scrolls'])->toBeTrue();
});
