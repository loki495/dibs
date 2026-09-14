@php
    $isMulti = ($mode ?? 'single') === 'multi';
    $creatingSentinel = $creatingSentinel ?? -1;
    $trimmedSearch = trim($searchValue);
    $hasExactMatch = $trimmedSearch !== '' && $options->contains(fn ($option) => \Illuminate\Support\Str::lower(trim($optionLabel($option))) === \Illuminate\Support\Str::lower($trimmedSearch));
    $canCreate = $trimmedSearch !== '' && ! $hasExactMatch && (($isMulti && isset($createModel)) || (! $isMulti && isset($createMethod)));
    $isCreating = ! $isMulti && ($selectedId ?? null) === $creatingSentinel;
    $creatingLabelMatchesSearch = $isCreating && \Illuminate\Support\Str::lower(trim($creatingLabel ?? '')) === \Illuminate\Support\Str::lower($trimmedSearch);
@endphp
<div>
    <div class="flex items-center justify-between gap-3">
        <flux:label>{{ $label }}</flux:label>
        @if (isset($hint))<span class="text-xs text-slate-500">{{ $hint }}</span>@endif
    </div>
    <flux:input type="search" wire:model.live.debounce.250ms="{{ $searchModel }}" placeholder="{{ $placeholder }}" class="mt-2" />
    <div class="mt-2 flex max-h-40 flex-wrap gap-2 overflow-y-auto rounded-xl border border-slate-200 p-2 dark:border-slate-800">
        @forelse ($options as $option)
            @php($isSelected = $isMulti ? in_array($option->id, $selectedIds ?? [], true) : ($selectedId ?? null) === $option->id)
            <button type="button"
                wire:click="{{ $isMulti ? $toggleMethod.'('.$option->id.')' : "\$set('{$valueModel}', {$option->id})" }}"
                @class(['rounded-full border px-2.5 py-1.5 text-xs transition',
                    'border-teal-600 bg-teal-100 text-teal-900 dark:border-teal-500 dark:bg-teal-950 dark:text-teal-100' => $isSelected,
                    'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => ! $isSelected])
                aria-pressed="{{ $isSelected ? 'true' : 'false' }}">{{ $optionLabel($option) }}</button>
        @empty
            <p class="px-1 py-1 text-xs text-slate-500">{{ $emptyText ?? __('No matches.') }}</p>
        @endforelse
        @if ($isCreating && ! $creatingLabelMatchesSearch && trim($creatingLabel ?? '') !== '')
            <span class="rounded-full border border-teal-600 bg-teal-100 px-2.5 py-1.5 text-xs text-teal-900 dark:border-teal-500 dark:bg-teal-950 dark:text-teal-100">{{ __('New: :name', ['name' => $creatingLabel]) }}</span>
        @endif
        @if ($canCreate)
            <button type="button"
                wire:click="{{ $isMulti ? "\$set('{$createModel}', ".\Illuminate\Support\Js::from($trimmedSearch).')' : $createMethod.'('.\Illuminate\Support\Js::from($trimmedSearch).')' }}"
                @class(['rounded-full border border-dashed px-2.5 py-1.5 text-xs transition',
                    'border-teal-600 bg-teal-100 text-teal-900 dark:border-teal-500 dark:bg-teal-950 dark:text-teal-100' => $isCreating,
                    'border-slate-300 text-slate-600 hover:border-slate-400 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800' => ! $isCreating])
                aria-pressed="{{ $isCreating ? 'true' : 'false' }}">{{ __('Create ":name"', ['name' => $trimmedSearch]) }}</button>
        @endif
    </div>
</div>
