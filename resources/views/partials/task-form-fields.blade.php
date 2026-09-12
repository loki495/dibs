<div class="space-y-5">
    <div class="space-y-4">
        <flux:input {{ $autofocus ?? false ? 'autofocus' : '' }} wire:model="{{ $titleModel }}" label="{{ __('Title') }}" placeholder="{{ __('What needs doing?') }}" />
        <flux:textarea wire:model="{{ $bodyModel }}" label="{{ __('Description') }}" rows="5" placeholder="{{ __('Notes, links, context, or checklist') }}" />
    </div>

    <div class="grid gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800 sm:grid-cols-2">
        <flux:select wire:model.live="{{ $areaModel }}" label="{{ __('Area') }}">
            <option value="0">{{ __('No area') }}</option>
            @foreach ($projects as $project)<option value="{{ $project->id }}">{{ $project->title }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="{{ $groupModel }}" label="{{ __('Group') }}">
            <option value="0">{{ __('No group') }}</option>
            @foreach ($groupsForForm as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }}</option>@endforeach
            <option value="-1">{{ __('New group…') }}</option>
        </flux:select>
        <flux:select wire:model="{{ $priorityModel }}" label="{{ __('Priority') }}">
            <option value="0">{{ __('No priority') }}</option>
            @foreach ($prioritiesForForm as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }}</option>@endforeach
        </flux:select>
        @if ($groupValueForForm === -1)
            <div class="sm:col-span-2"><flux:input autofocus wire:model="{{ $newGroupModel }}" label="{{ __('New Group') }}" placeholder="{{ __('Name this website or area') }}" /></div>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:input type="search" wire:model.live.debounce.250ms="{{ $parentSearchModel }}" label="{{ __('Find parent') }}" placeholder="{{ __('Search task title or #number') }}" />
        <flux:select wire:model="{{ $parentModel }}" label="{{ __('Parent') }}">
            <option value="0">{{ __('No parent') }}</option>
            @foreach ($parentsForForm as $candidate)<option value="{{ $candidate->id }}">#{{ $candidate->github_number }} {{ $candidate->title }}</option>@endforeach
        </flux:select>
    </div>

    <div class="border-t border-slate-200 pt-5 dark:border-slate-800">
        <div class="flex items-center justify-between gap-3"><flux:label>{{ __('Labels') }}</flux:label><span class="text-xs text-slate-500">{{ __('Choose any that apply') }}</span></div>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($labelOptionsForForm as $label)
                <button type="button" wire:click="{{ $toggleLabelMethod }}({{ $label->id }})" @class(['rounded-full border px-2.5 py-1.5 text-xs transition', 'border-teal-600 bg-teal-100 text-teal-900 dark:border-teal-500 dark:bg-teal-950 dark:text-teal-100' => in_array($label->id, $selectedLabelsForForm, true), 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => ! in_array($label->id, $selectedLabelsForForm, true)]) aria-pressed="{{ in_array($label->id, $selectedLabelsForForm, true) ? 'true' : 'false' }}">{{ $label->name }}</button>
            @endforeach
        </div>
        <div class="mt-4"><flux:input wire:model="{{ $newLabelModel }}" label="{{ __('New label') }}" placeholder="{{ __('Optional — creates and applies it') }}" /></div>
    </div>
</div>
