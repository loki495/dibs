<div wire:key="detail-{{ $detail['issue']->id }}" x-data x-init="$nextTick(() => $refs.close.focus())" x-trap.inert.noscroll="true" role="dialog" aria-modal="true" aria-labelledby="issue-detail-title" class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-slate-950/40 backdrop-blur-[2px]" @click="$wire.set('selected', 0)" aria-hidden="true"></div>
    <section class="absolute inset-y-0 right-0 w-full max-w-2xl overflow-y-auto bg-white shadow-2xl dark:bg-slate-900">
        <header class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
            <span class="text-xs text-slate-500">{{ $detail['issue']->repository->full_name }} · #{{ $detail['issue']->github_number }}</span>
            <button x-ref="close" wire:click="$set('selected', 0)" class="flex size-11 items-center justify-center rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="{{ __('Close issue details') }}"><flux:icon.x-mark class="size-5" /></button>
        </header>
        <div class="space-y-7 p-5 sm:p-8">
            @if ($editingIssue)
                <form wire:submit="saveIssue" class="space-y-5">
                    <div class="flex items-center justify-between gap-3"><span class="text-xs font-medium uppercase tracking-wider text-teal-700 dark:text-teal-400">{{ __('Edit task') }}</span><flux:button type="button" wire:click="cancelEdit" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button></div>
                    @include('partials.task-form-fields', ['titleModel' => 'editTitle', 'bodyModel' => 'editBody', 'areaModel' => 'editArea', 'groupModel' => 'editGroup', 'newGroupModel' => 'editNewGroup', 'parentSearchModel' => 'editParentSearch', 'parentModel' => 'editParent', 'toggleLabelMethod' => 'toggleEditLabel', 'selectedLabelsForForm' => $editLabels, 'labelOptionsForForm' => $editLabelOptions, 'groupsForForm' => $editGroups, 'priorityModel' => 'editPriority', 'prioritiesForForm' => $editPriorities, 'parentsForForm' => $editParents, 'newLabelModel' => 'editNewLabel', 'groupValueForForm' => $editGroup])
                    @error('editTitle')<p role="alert" class="text-sm text-amber-700 dark:text-amber-400">{{ $message }}</p>@enderror
                    @if ($editError)<p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">{{ $editError }}</p>@endif
                    <div class="flex justify-end"><flux:button type="submit" wire:loading.attr="disabled" wire:target="saveIssue"><span wire:loading.remove wire:target="saveIssue">{{ __('Save changes') }}</span><span wire:loading wire:target="saveIssue">{{ __('Saving…') }}</span></flux:button></div>
                </form>
            @else
                <div>
                    <div class="flex items-start justify-between gap-4"><div><span @class(['text-xs font-medium uppercase tracking-wider', 'text-teal-700 dark:text-teal-400' => $detail['issue']->state === 'OPEN', 'text-slate-500' => $detail['issue']->state !== 'OPEN'])>{{ $detail['issue']->state === 'OPEN' ? __('Open') : __('Closed') }}</span><h2 id="issue-detail-title" class="mt-3 break-words text-2xl font-semibold leading-snug tracking-tight">{{ $detail['issue']->title }}</h2></div><div class="flex shrink-0 gap-1">@if ($detail['issue']->state === 'OPEN')<flux:button type="button" wire:click="closeIssue" variant="primary" size="sm" icon="check">{{ __('Mark done') }}</flux:button>@endif<flux:button type="button" wire:click="beginEdit" variant="ghost" size="sm" icon="pencil-square">{{ __('Edit') }}</flux:button></div></div>
                    <div class="mt-4 flex flex-wrap gap-2">@foreach ($detail['issue']->labels as $item)<span class="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $item->name }}</span>@endforeach</div>
                    @if ($detail['issue']->url)<a href="{{ $detail['issue']->url }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex min-h-10 items-center gap-2 text-sm text-teal-700 underline-offset-4 hover:underline dark:text-teal-400">{{ __('Open in GitHub') }}<flux:icon.arrow-up-right class="size-4" /></a>@endif
                </div>
            @endif
            @if ($detail['issue']->projectItems->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($detail['issue']->projectItems as $membership)
                        <div class="rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-950/60">
                            <p class="font-medium">{{ $membership->project->title }}</p>
                            <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-5 gap-y-2 text-xs">
                                @foreach ([__('Group') => $membership->groupOption?->name, __('Priority') => $membership->priorityOption?->name, __('Status') => $membership->statusOption?->name, __('Planned') => $membership->planned_on?->toDateString(), __('Due') => $membership->due_on?->toDateString(), __('Repeat') => $membership->repeat_rule] as $key => $value)
                                    @if ($value)<dt class="text-slate-500">{{ $key }}</dt><dd class="break-words">{{ $value }}</dd>@endif
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                </div>
            @endif
            @if (! $editingIssue)
            <section aria-label="{{ __('Description') }}">
                @if ($detail['body'])<div class="markdown-body text-sm text-slate-700 dark:text-slate-300">{!! $detail['body'] !!}</div>@else<p class="text-sm text-slate-500">{{ __('No notes yet.') }}</p>@endif
            </section>
            @endif
            <section class="border-t border-slate-200 pt-6 dark:border-slate-800">
                <h3 class="mb-5 text-sm font-semibold">{{ __('Discussion & history') }}</h3>
                @forelse ($detail['comments'] as $comment)
                    <article class="mb-6">
                        <div class="mb-2 flex items-center justify-between gap-3"><p class="text-xs text-slate-500"><span class="font-medium text-slate-700 dark:text-slate-300">{{ $comment['author'] ?? __('Unknown author') }}</span> · {{ $comment['date'] }}</p><flux:button type="button" wire:click="beginEditComment({{ $comment['id'] }})" variant="ghost" size="sm">{{ __('Edit') }}</flux:button></div>
                        @if ($editingComment === $comment['id'])
                            <form wire:submit="saveComment" class="space-y-3"><flux:textarea wire:model="editCommentBody" label="{{ __('Comment') }}" rows="5" />@error('editCommentBody')<p role="alert" class="text-sm text-amber-700 dark:text-amber-400">{{ $message }}</p>@enderror<div class="flex justify-end gap-2"><flux:button type="button" wire:click="cancelEditComment" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button><flux:button type="submit" size="sm">{{ __('Save comment') }}</flux:button></div></form>
                        @else <div class="markdown-body text-sm text-slate-700 dark:text-slate-300">{!! $comment['body'] !!}</div>@endif
                    </article>
                @empty<p class="text-sm text-slate-500">{{ __('No saved comments.') }}</p>@endforelse
                <form wire:submit="addComment" class="mt-6 space-y-3"><flux:textarea wire:model="newCommentBody" label="{{ __('Add a comment') }}" rows="4" placeholder="{{ __('Add context, a result, or a follow-up') }}" />@error('newCommentBody')<p role="alert" class="text-sm text-amber-700 dark:text-amber-400">{{ $message }}</p>@enderror@if ($commentError)<p role="alert" class="text-sm text-amber-700 dark:text-amber-400">{{ $commentError }}</p>@endif<div class="flex justify-end"><flux:button type="submit" size="sm">{{ __('Add comment') }}</flux:button></div></form>
            </section>
        </div>
    </section>
</div>
