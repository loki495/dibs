@component('layouts.app')
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <a href="{{ route('workspace') }}" class="text-sm text-teal-700 dark:text-teal-300">{{ __('Back to workspace') }}</a>
            <h1 class="mt-3 text-2xl font-semibold">{{ __('Data export/import') }}</h1>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ __('Download your workspace as JSON, or restore an archive into an empty workspace.') }}</p>
        </div>
        @if (session('transfer-message'))
            <p role="status" class="rounded-xl bg-teal-50 p-4 text-teal-900 dark:bg-teal-950 dark:text-teal-100">{{ session('transfer-message') }}</p>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-amber-50 p-4 text-amber-950 dark:bg-amber-950 dark:text-amber-100">
                @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif
        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-lg font-semibold">{{ __('Export workspace') }}</h2>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ __('Includes all projects, groups, tasks, subtasks, labels, statuses, priorities, schedules, comments, knowledge, capture drafts and activity history, including archived records.') }}</p>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ __('Login credentials, server tokens, live claims, pending jobs and GitHub push queues are excluded. The file contains your private workspace text.') }}</p>
            <a href="{{ route('data-transfer.export') }}" class="mt-4 inline-flex min-h-11 items-center rounded-lg bg-teal-800 px-4 py-2 font-medium text-white hover:bg-teal-700">{{ __('Download JSON') }}</a>
        </section>
        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-lg font-semibold">{{ __('Import workspace') }}</h2>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ __('Upload a DIBS JSON export to validate it and preview record counts. Nothing is imported until you confirm. Import does not contact GitHub or replay queued work.') }}</p>
            @unless ($empty)
                <p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">{{ __('This workspace already contains data. You can export or preview files, but import is blocked to protect existing records. Use a fresh instance for restoration.') }}</p>
            @endunless
            <form method="POST" action="{{ route('data-transfer.preview') }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                @csrf
                <label for="archive" class="block text-sm font-medium">{{ __('DIBS JSON archive') }}</label>
                <input id="archive" name="archive" type="file" accept=".json,application/json" required class="block w-full rounded-lg border border-slate-300 p-2 text-sm dark:border-slate-700">
                <p class="text-xs text-slate-500">{{ __('Maximum file size: :size MiB. Your server may apply a smaller upload limit.', ['size' => config('workspace-transfer.max_file_kib') / 1024]) }}</p>
                <button type="submit" class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">{{ __('Validate and preview') }}</button>
            </form>
            @if ($preview)
                <div class="mt-6 border-t border-slate-200 pt-4 dark:border-slate-800">
                    <h3 class="font-semibold">{{ __('Import preview') }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Preview expires after :minutes minutes. Capture drafts will belong to your signed-in account.', ['minutes' => config('workspace-transfer.preview_minutes')]) }}</p>
                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        @foreach ($preview['counts'] as $section => $count)
                            <dt>{{ __(\App\Support\WorkspaceArchive::SECTION_LABELS[$section]) }}</dt><dd class="text-right tabular-nums">{{ $count }}</dd>
                        @endforeach
                    </dl>
                    <form method="POST" action="{{ route('data-transfer.import') }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="flex items-start gap-2 text-sm"><input name="confirm" type="checkbox" value="1" required @disabled(! $empty) class="mt-1">{{ __('I confirm restoration into this empty workspace. GitHub associations in the archive will be preserved.') }}</label>
                        <button type="submit" @disabled(! $empty) class="min-h-11 rounded-lg bg-teal-800 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-50">{{ __('Import archive') }}</button>
                    </form>
                    <form method="POST" action="{{ route('data-transfer.cancel') }}" class="mt-3">@csrf<button type="submit" class="min-h-11 text-sm text-slate-600 dark:text-slate-400">{{ __('Discard preview') }}</button></form>
                </div>
            @endif
        </section>
    </div>
@endcomponent
