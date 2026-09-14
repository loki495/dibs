<div class="space-y-5">
    <div class="space-y-4">
        <flux:input :autofocus="$autofocus ?? false" wire:model="{{ $titleModel }}" label="{{ __('Title') }}" placeholder="{{ __('What needs doing?') }}" />
        <flux:textarea wire:model="{{ $bodyModel }}" label="{{ __('Description') }}" rows="5" placeholder="{{ __('Notes, links, context, or checklist') }}" />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:select wire:model.live="{{ $areaModel }}" label="{{ __('Area') }}">
            <option value="0">{{ __('No area') }}</option>
            @foreach ($projects as $project)<option value="{{ $project->id }}">{{ $project->title }}</option>@endforeach
        </flux:select>
        <flux:select wire:model="{{ $priorityModel }}" label="{{ __('Priority') }}">
            <option value="0">{{ __('No priority') }}</option>
            @foreach ($prioritiesForForm as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }}</option>@endforeach
        </flux:select>
    </div>

    @include('partials.searchable-picker', [
        'label' => __('Group'),
        'searchModel' => $groupSearchModel,
        'searchValue' => $groupSearchValueForForm,
        'placeholder' => __('Search or create a group'),
        'options' => $groupsForForm,
        'optionLabel' => fn ($option) => $option->name,
        'mode' => 'single',
        'valueModel' => $groupModel,
        'selectedId' => $groupValueForForm,
        'createMethod' => $newGroupMethod,
        'creatingLabel' => $newGroupValueForForm,
        'emptyText' => __('No groups match.'),
    ])

    <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
        @include('partials.searchable-picker', [
            'label' => __('Parent'),
            'searchModel' => $parentSearchModel,
            'searchValue' => $parentSearchValueForForm,
            'placeholder' => __('Search task title or #number'),
            'options' => $parentsForForm,
            'optionLabel' => function ($option) {
                $membership = $option->projectItems->first();
                $context = $membership ? ' — '.$membership->project->title.($membership->groupOption ? ' / '.$membership->groupOption->name : '') : '';

                return '#'.$option->github_number.' '.$option->title.$context;
            },
            'mode' => 'single',
            'valueModel' => $parentModel,
            'selectedId' => $parentValueForForm,
            'emptyText' => __('No tasks match.'),
        ])
    </div>

    <div class="border-t border-slate-200 pt-5 dark:border-slate-800">
        @include('partials.searchable-picker', [
            'label' => __('Labels'),
            'hint' => __('Choose any that apply'),
            'searchModel' => $labelSearchModel,
            'searchValue' => $labelSearchValueForForm,
            'placeholder' => __('Search or create a label'),
            'options' => $labelOptionsForForm,
            'optionLabel' => fn ($option) => $option->name,
            'mode' => 'multi',
            'toggleMethod' => $toggleLabelMethod,
            'selectedIds' => $selectedLabelsForForm,
            'createMethod' => $newLabelMethod,
            'creatingLabels' => $newLabelsForForm,
            'removeCreatingMethod' => $removeNewLabelMethod,
            'emptyText' => __('No labels match.'),
        ])
    </div>
</div>
