<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('provisions an account with a hashed password', function (): void {
    $this->artisan('todo:user')
        ->expectsQuestion('Name', 'Andres')
        ->expectsQuestion('Email', 'owner@example.test')
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-test-password')
        ->expectsQuestion('Confirm password', 'a-long-test-password')
        ->expectsOutput('Workspace account created.')
        ->assertSuccessful();
    $user = User::query()->sole();
    expect(Hash::check('a-long-test-password', $user->password))->toBeTrue();
});

it('rejects an invalid account without creating a user', function (): void {
    $this->artisan('todo:user')
        ->expectsQuestion('Name', 'Andres')
        ->expectsQuestion('Email', 'invalid')
        ->expectsQuestion('Password (at least 12 characters)', 'short')
        ->expectsQuestion('Confirm password', 'different')
        ->expectsOutput('The email field must be a valid email address.')
        ->expectsOutput('The password field must be at least 12 characters.')
        ->expectsOutput('The password field confirmation does not match.')
        ->assertFailed();
    expect(User::query()->count())->toBe(0);
});
