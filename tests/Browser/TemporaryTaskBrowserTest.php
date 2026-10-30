<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\User;

it('marks and consumes temporary tasks without leaving a stale detail panel', function (int $width, string $theme): void {
    $this->actingAs(User::factory()->create());
    $task = Issue::factory()->temporary()->create(['title' => 'Session handoff']);
    $page = visit('/')->resize($width, 844);
    $page->script("window.todoTheme.set('{$theme}')");
    $page->click('Session handoff')->assertSee('Temporary')->assertSee('Discussion & history')
        ->assertNoJavascriptErrors()->screenshot(filename: "temporary-{$width}-{$theme}");
    $page->click('button[aria-label="Mark done"]')->assertDontSee('Session handoff')
        ->assertNoJavascriptErrors();
    expect(Issue::find($task->id))->toBeNull();
})->with([[390, 'light'], [390, 'dark'], [1280, 'light'], [1280, 'dark']]);
