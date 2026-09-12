<?php

use App\Actions\AuthenticateUser;
use Livewire\Component;

new class extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(AuthenticateUser $authenticate): void
    {
        $this->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $authenticate->handle($this->email, $this->password, request()->ip() ?? 'unknown');
        session()->regenerate();
        $this->redirectIntended(default: '/');
    }
}; ?>

<div class="mx-auto max-w-md pt-16">
    <p class="text-sm font-medium text-teal-700 dark:text-teal-400">{{ config('app.name') }}</p>
    <h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ __('Sign in') }}</h1>
    <p class="mt-3 text-slate-600 dark:text-slate-400">{{ __('Your tasks, projects, and learning in one place.') }}</p>
    <form wire:submit="login" class="mt-8 space-y-5">
        <div>
            <label for="email" class="block text-sm font-medium">{{ __('Email') }}</label>
            <input id="email" type="email" wire:model="email" autocomplete="username" required autofocus class="mt-2 w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2">
            @error('email') <p role="alert" class="mt-2 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password" class="block text-sm font-medium">{{ __('Password') }}</label>
            <input id="password" type="password" wire:model="password" autocomplete="current-password" required class="mt-2 w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2">
            @error('password') <p role="alert" class="mt-2 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <button type="submit" wire:loading.attr="disabled" class="w-full rounded-lg bg-teal-800 dark:bg-teal-700 px-4 py-3 font-medium text-white disabled:opacity-50">{{ __('Sign in') }}</button>
    </form>
</div>
