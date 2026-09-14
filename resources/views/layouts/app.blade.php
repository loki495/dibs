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
    <div class="mx-auto flex max-w-[1600px] items-center justify-between gap-4 px-6 pt-4 pb-3">
        <a href="{{ route('workspace') }}" class="flex items-center gap-3 text-xl font-semibold tracking-tight">
            <span class="flex size-10 items-center justify-center rounded-xl bg-teal-800 text-white"><flux:icon.check class="size-6" /></span>
            {{ config('app.name') }}
        </a>
        <div @class(['lg:hidden' => request()->routeIs('workspace')])>
            <livewire:top-bar />
        </div>
    </div>
    <main class="mx-auto max-w-[1600px] px-6 pb-12">{{ $slot }}</main>
    @livewireScripts
    @fluxScripts
</body>
</html>
