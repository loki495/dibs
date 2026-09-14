<?php

declare(strict_types=1);

use App\Actions\SyncGitHub;
use App\Services\GitHub\GitHubSyncException;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public string $variant = 'icon';

    public ?string $refreshMessage = null;

    public ?string $refreshError = null;

    public int $currentArea = 0;

    public function mount(): void
    {
        $this->currentArea = (int) request()->query('area', 0);
    }

    #[On('area-changed')]
    public function updateCurrentArea(int $area): void
    {
        $this->currentArea = $area;
    }

    public function refreshFromGitHub(): void
    {
        $this->reset('refreshMessage', 'refreshError');

        $token = (string) config('github.token');
        if ($token === '') {
            $this->refreshError = 'In-app refresh needs a server-side GitHub token. Set GITHUB_TOKEN and try again.';

            return;
        }

        try {
            app(SyncGitHub::class)->handle($token, comments: true);
            $this->refreshMessage = 'Updated from GitHub just now.';
        } catch (GitHubSyncException $exception) {
            $this->refreshError = $exception->getMessage();
        }
    }
}; ?>

@php($queueCounts = config('dibs.push_queue_ui_enabled') && auth()->check() ? app(\App\Actions\DescribeGitHubPushQueue::class)->counts() : ['actionable' => 0, 'pending' => 0])
<div class="relative" x-data="{ settingsOpen: false, theme: window.todoTheme.get() }" @click.outside="settingsOpen = false">
    @if ($variant === 'labeled')
        <button type="button" @click="settingsOpen = ! settingsOpen" :aria-expanded="settingsOpen.toString()" aria-label="{{ __('Settings') }}" class="relative flex min-h-11 w-full items-center gap-2 rounded-xl px-2.5 py-1.5 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
            <flux:icon.cog-6-tooth class="size-4 shrink-0 text-slate-500" />
            <span class="min-w-0 flex-1 truncate">{{ auth()->user()?->name ?? __('Settings') }}</span>
            @if ($queueCounts['actionable'] > 0)
                <span class="size-2 shrink-0 rounded-full bg-red-500" aria-hidden="true"></span>
            @elseif ($queueCounts['pending'] > 0)
                <span class="size-2 shrink-0 rounded-full bg-slate-400" aria-hidden="true"></span>
            @endif
        </button>
    @else
        <button type="button" @click="settingsOpen = ! settingsOpen" :aria-expanded="settingsOpen.toString()" aria-label="{{ __('Settings') }}" class="relative flex size-10 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">
            <flux:icon.cog-6-tooth class="size-5" />
            @if ($queueCounts['actionable'] > 0)
                <span class="absolute right-1.5 top-1.5 size-2 rounded-full bg-red-500" aria-hidden="true"></span>
            @elseif ($queueCounts['pending'] > 0)
                <span class="absolute right-1.5 top-1.5 size-2 rounded-full bg-slate-400" aria-hidden="true"></span>
            @endif
        </button>
    @endif
    <div x-show="settingsOpen" x-cloak x-transition.origin.top.right class="absolute right-0 z-20 mt-2 w-64 space-y-3 rounded-xl border border-slate-200 bg-white p-3 shadow-lg dark:border-slate-800 dark:bg-slate-900">
        @auth
            <p class="truncate px-2 pb-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Signed in as :name', ['name' => auth()->user()->name]) }}</p>
            @if (config('dibs.push_queue_ui_enabled') && $variant !== 'labeled')
                <a href="{{ route('push-queue') }}" class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                    {{ __('Push queue') }}
                    @if ($queueCounts['actionable'] > 0)
                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-900 dark:bg-red-900/30 dark:text-red-300">{{ $queueCounts['actionable'] }}</span>
                    @elseif ($queueCounts['pending'] > 0)
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium dark:bg-slate-800">{{ $queueCounts['pending'] }}</span>
                    @endif
                </a>
            @endif
            <button type="button" wire:click="refreshFromGitHub" wire:loading.attr="disabled" wire:target="refreshFromGitHub" class="flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                <span wire:loading.remove wire:target="refreshFromGitHub">{{ __('Refresh from GitHub') }}</span>
                <span wire:loading wire:target="refreshFromGitHub">{{ __('Refreshing…') }}</span>
            </button>
            @if ($currentArea > 0)
                <button type="button" wire:click="$dispatch('open-project-settings')" class="flex w-full items-center rounded-lg px-2 py-1.5 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800 md:hidden">{{ __('Project settings') }}</button>
            @endif
            <button type="button" wire:click="$dispatch('open-manage-labels')" class="flex w-full items-center rounded-lg px-2 py-1.5 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800">{{ __('Manage labels') }}</button>
        @endauth
        <label class="flex items-center justify-between gap-2 px-2 py-1.5 text-sm" for="theme">{{ __('Theme') }}
            <select id="theme" x-model="theme" @change="window.todoTheme.set(theme)" class="min-h-9 rounded-lg border border-slate-300 bg-white px-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                <option value="system">{{ __('System') }}</option>
                <option value="light">{{ __('Light') }}</option>
                <option value="dark">{{ __('Dark') }}</option>
            </select>
        </label>
        @auth
            <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100 pt-2 dark:border-slate-800">@csrf
                <button type="submit" class="flex w-full items-center rounded-lg px-2 py-1.5 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950">{{ __('Sign out') }}</button>
            </form>
        @endauth
    </div>
    @if ($refreshMessage)
        <div role="status" class="absolute right-0 top-12 z-20 w-64 rounded-xl bg-teal-50 px-4 py-3 text-sm text-teal-900 shadow-lg dark:bg-teal-950 dark:text-teal-100">{{ $refreshMessage }}</div>
    @endif
    @if ($refreshError)
        <div role="alert" class="absolute right-0 top-12 z-20 w-64 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-950 shadow-lg dark:bg-amber-950 dark:text-amber-100">{{ $refreshError }}</div>
    @endif
</div>
