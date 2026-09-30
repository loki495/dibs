<?php

declare(strict_types=1);

use App\Models\GitHubRepository;
use App\Models\Label;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('opens Manage labels from the gear menu on a page other than the workspace', function (): void {
    User::factory()->create(['email' => 'owner@example.com', 'password' => Hash::make('a-strong-password')]);
    Label::factory()->for(GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]), 'repository')->create(['name' => 'urgent']);

    $page = visit('/login')->resize(390, 664)->fill('email', 'owner@example.com')->fill('password', 'a-strong-password')
        ->click('button[type="submit"]')->assertPathIs('/');

    $page->navigate('/push-queue')->click('button[aria-label="Settings"]:visible')->click('button:visible:has-text("Manage labels")')
        ->wait(1)->assertSee('Rename or delete a label')->assertSee('urgent');
});
