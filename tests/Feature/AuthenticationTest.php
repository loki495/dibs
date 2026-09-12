<?php

declare(strict_types=1);

use App\Actions\AuthenticateUser;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('requires authentication for the workspace', function (): void {
    $this->get('/')->assertRedirect('/login');
    $this->get('/login')->assertOk()->assertSee('Sign in');
});

it('authenticates a valid account through the action', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);
    $authenticated = app(AuthenticateUser::class)->handle($user->email, 'correct-password', '127.0.0.1');
    expect($authenticated->is($user))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('rejects incorrect credentials without authenticating', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);
    expect(fn () => app(AuthenticateUser::class)->handle($user->email, 'wrong-password', '127.0.0.1'))
        ->toThrow(ValidationException::class, 'These credentials do not match our records.');
    $this->assertGuest();
});

it('limits repeated failed authentication attempts', function (): void {
    $email = fake()->unique()->safeEmail();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            app(AuthenticateUser::class)->handle($email, 'wrong-password', '127.0.0.1');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('email');
        }
    }
    expect(fn () => app(AuthenticateUser::class)->handle($email, 'wrong-password', '127.0.0.1'))
        ->toThrow(ValidationException::class, 'Too many sign-in attempts.');
    $this->assertGuest();
});

it('validates the login form', function (): void {
    Livewire::test('pages::auth.login')
        ->set('email', 'not-an-email')->set('password', '')
        ->call('login')->assertHasErrors(['email' => 'email', 'password' => 'required']);
    $this->assertGuest();
});

it('signs in through the single-file component', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);
    Livewire::test('pages::auth.login')->set('email', $user->email)->set('password', 'correct-password')
        ->call('login')->assertHasNoErrors()->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('shows the empty workspace and signs out', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/')->assertOk()->assertSee('Your workspace is ready');
    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

it('does not expose public account registration', function (): void {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['email' => fake()->safeEmail()])->assertNotFound();
});
