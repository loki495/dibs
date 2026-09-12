<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <script>
        (() => {
            let theme = 'system';
            try { theme = localStorage.getItem('todo-theme') || 'system'; } catch {}
            const media = matchMedia('(prefers-color-scheme: dark)');
            const apply = () => {
                const dark = theme === 'dark' || (theme === 'system' && media.matches);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            };
            window.todoTheme = { get: () => theme, set: value => {
                theme = value;
                try { localStorage.setItem('todo-theme', value); } catch {}
                apply();
            }};
            media.addEventListener('change', apply);
            apply();
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 antialiased">
    <div class="mx-auto flex max-w-[1600px] items-center justify-end gap-4 px-6 pt-4" x-data="{ theme: window.todoTheme.get() }">
        @auth
            @if (config('todo.push_queue_ui_enabled'))
                @php($queueCounts = app(\App\Actions\DescribeGitHubPushQueue::class)->counts())
                <a href="{{ route('push-queue') }}" class="flex items-center gap-2 text-sm text-slate-600 hover:underline dark:text-slate-400">
                    {{ __('Push queue') }}
                    @if ($queueCounts['actionable'] > 0)
                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-900 dark:bg-red-900/30 dark:text-red-300">{{ $queueCounts['actionable'] }}</span>
                    @elseif ($queueCounts['pending'] > 0)
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium dark:bg-slate-800">{{ $queueCounts['pending'] }}</span>
                    @endif
                </a>
            @endif
        @endauth
        <label class="flex items-center gap-2 text-sm" for="theme">{{ __('Theme') }}
            <select id="theme" x-model="theme" @change="window.todoTheme.set(theme)" class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 dark:border-slate-700 dark:bg-slate-900">
                <option value="system">{{ __('System') }}</option>
                <option value="light">{{ __('Light') }}</option>
                <option value="dark">{{ __('Dark') }}</option>
            </select>
        </label>
    </div>
    <main class="mx-auto max-w-[1600px] px-6 pb-12">{{ $slot }}</main>
    @livewireScripts
    @fluxScripts
</body>
</html>
