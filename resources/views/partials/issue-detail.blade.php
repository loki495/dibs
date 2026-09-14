<div wire:key="detail-{{ $detail['issue']->id }}" x-data x-init="$nextTick(() => $refs.close.focus())" x-trap.inert.noscroll="true" role="dialog" aria-modal="true" aria-labelledby="issue-detail-title" class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-slate-950/40 backdrop-blur-[2px]" @click="$wire.set('selected', 0)" aria-hidden="true"></div>
    <section class="absolute inset-y-0 right-0 flex w-full max-w-2xl flex-col bg-white shadow-2xl dark:bg-slate-900">
        <header class="shrink-0 border-b border-slate-200 bg-white px-5 py-3 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3">
                <span class="text-xs text-slate-500">{{ $detail['issue']->repository->full_name }} · #{{ $detail['issue']->github_number }}</span>
                <div class="flex items-center gap-1">
                    @if (! $editingIssue)
                        @if ($detail['issue']->state === 'OPEN')<button type="button" wire:click="closeIssue" class="flex size-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="{{ __('Mark done') }}"><flux:icon.check class="size-4" /></button>@endif
                        <button type="button" wire:click="beginEdit" class="flex size-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="{{ __('Edit') }}"><flux:icon.pencil-square class="size-4" /></button>
                        <button type="button" wire:click="openDeleteConfirm" class="flex size-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="{{ __('Delete') }}"><flux:icon.trash class="size-4" /></button>
                    @endif
                    @if ($detail['issue']->url)<a href="{{ $detail['issue']->url }}" target="_blank" rel="noopener noreferrer" class="flex size-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="{{ __('Open in GitHub') }}"><flux:icon.arrow-up-right class="size-4" /></a>@endif
                    <button x-ref="close" wire:click="$set('selected', 0)" class="flex size-9 items-center justify-center rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="{{ __('Close issue details') }}"><flux:icon.x-mark class="size-5" /></button>
                </div>
            </div>
            @if (! $editingIssue)
                <div class="mt-2">
                    <h2 id="issue-detail-title" class="w-full break-words text-2xl font-semibold leading-snug tracking-tight">{{ $detail['issue']->title }}</h2>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        <span @class(['text-xs font-medium uppercase tracking-wider', 'text-teal-700 dark:text-teal-400' => $detail['issue']->state === 'OPEN', 'text-slate-500' => $detail['issue']->state !== 'OPEN'])>{{ $detail['issue']->state === 'OPEN' ? __('Open') : __('Closed') }}</span>
                        @foreach ($detail['issue']->projectItems as $membership)
                            @php($membershipColor = app(\App\Support\ProjectColor::class)->for($membership->project))
                            <span class="rounded-full border px-2 py-0.5 text-xs font-medium" style="border-color: {{ $membershipColor }}; background-color: color-mix(in srgb, {{ $membershipColor }} 14%, transparent); color: {{ $membershipColor }}">{{ $membership->project->title }}</span>
                            @if ($membership->groupOption)<span class="rounded-full border border-slate-200 px-2 py-0.5 text-xs text-slate-600 dark:border-slate-700 dark:text-slate-300">{{ $membership->groupOption->name }}</span>@endif
                            @if ($membership->statusOption)<span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $membership->statusOption->name }}</span>@endif
                        @endforeach
                        @if ($detail['pushQueuePending'])<a href="{{ route('push-queue') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-2 py-0.5 text-xs text-amber-900 hover:underline dark:bg-amber-950 dark:text-amber-100"><flux:icon.arrow-path class="size-3" />{{ __('Pending GitHub sync') }}</a>@endif
                    </div>
                </div>
            @else
                <div class="mt-2 flex items-center justify-between gap-3"><span class="text-xs font-medium uppercase tracking-wider text-teal-700 dark:text-teal-400">{{ __('Edit task') }}</span><flux:button type="button" wire:click="cancelEdit" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button></div>
            @endif
        </header>
        <div class="flex-1 space-y-5 overflow-y-auto p-5">
            @if ($editingIssue)
                <form wire:submit="saveIssue" class="space-y-4">
                    @include('partials.task-form-fields', ['titleModel' => 'editTitle', 'bodyModel' => 'editBody', 'areaModel' => 'editArea', 'groupModel' => 'editGroup', 'groupSearchModel' => 'editGroupSearch', 'groupSearchValueForForm' => $editGroupSearch, 'newGroupMethod' => 'selectNewEditGroup', 'newGroupValueForForm' => $editNewGroup, 'parentSearchModel' => 'editParentSearch', 'parentSearchValueForForm' => $editParentSearch, 'parentModel' => 'editParent', 'parentValueForForm' => $editParent, 'toggleLabelMethod' => 'toggleEditLabel', 'selectedLabelsForForm' => $editLabels, 'labelOptionsForForm' => $editLabelOptions, 'labelSearchModel' => 'editLabelSearch', 'labelSearchValueForForm' => $editLabelSearch, 'groupsForForm' => $editGroups, 'priorityModel' => 'editPriority', 'prioritiesForForm' => $editPriorities, 'parentsForForm' => $editParents, 'newLabelMethod' => 'addEditNewLabel', 'newLabelsForForm' => $editNewLabels, 'removeNewLabelMethod' => 'removeEditNewLabel', 'groupValueForForm' => $editGroup])
                    @error('editTitle')<p role="alert" class="text-sm text-amber-700 dark:text-amber-400">{{ $message }}</p>@enderror
                    @if ($editError)<p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">{{ $editError }}</p>@endif
                    <div class="flex justify-end"><flux:button type="submit" wire:loading.attr="disabled" wire:target="saveIssue"><span wire:loading.remove wire:target="saveIssue">{{ __('Save changes') }}</span><span wire:loading wire:target="saveIssue">{{ __('Saving…') }}</span></flux:button></div>
                </form>
            @else
                <div class="flex flex-wrap gap-2">@foreach ($detail['issue']->labels as $item)<span class="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $item->name }}</span>@endforeach</div>
            @endif

            @if ($detail['claim'])
                @php($claim = $detail['claim'])
                <div @class(['rounded-xl border p-4 text-sm', 'border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-950/40' => $claim['isExpired'], 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30' => ! $claim['isExpired'] && $claim['isCurrentlyAlive'] === false, 'border-teal-200 bg-teal-50 dark:border-teal-900 dark:bg-teal-950/30' => ! $claim['isExpired'] && $claim['isCurrentlyAlive'] !== false])>
                    <div class="flex items-center justify-between gap-3">
                        <p class="font-medium">
                            {{ __('Claimed by :agent', ['agent' => $claim['agentName']]) }}
                            @if ($claim['isExpired'])<span class="ml-1 text-xs font-normal text-slate-500">{{ __('(lease expired)') }}</span>
                            @elseif ($claim['isCurrentlyAlive'] === false)<span class="ml-1 text-xs font-normal text-red-700 dark:text-red-400">{{ __('(process no longer alive)') }}</span>
                            @elseif ($claim['isCurrentlyAlive'] === null)<span class="ml-1 text-xs font-normal text-slate-500">{{ __('(liveness unverifiable)') }}</span>
                            @endif
                        </p>
                        <flux:button type="button" wire:click="releaseClaim" wire:confirm="{{ __('Release this claim? The agent holding it will lose access.') }}" variant="ghost" size="sm">{{ __('Release claim') }}</flux:button>
                    </div>
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-slate-600 dark:text-slate-400">
                        @if ($claim['host'])<dt>{{ __('Host') }}</dt><dd>{{ $claim['host'] }}</dd>@endif
                        <dt>{{ __('Process') }}</dt><dd>{{ __('pid :pid', ['pid' => $claim['pid']]) }}</dd>
                        <dt>{{ __('Last heartbeat') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($claim['lastSeenAt'])->diffForHumans() }}</dd>
                        <dt>{{ __('Lease expires') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($claim['expiresAt'])->diffForHumans() }}</dd>
                    </dl>
                    @if ($claimError)<p role="alert" class="mt-2 text-xs text-amber-700 dark:text-amber-400">{{ $claimError }}</p>@endif
                </div>
            @endif

            @if ($detail['parent'] || $detail['children'] || $detail['knowledge'])
                <nav aria-label="{{ __('Plan navigation') }}" class="space-y-3 text-sm">
                    @if ($detail['parent'])
                        <div>
                            <p class="mb-1 text-xs font-medium uppercase tracking-wider text-slate-500">{{ __('Parent') }}</p>
                            <button type="button" wire:click="$set('selected', {{ $detail['parent']['id'] }})" class="text-left text-teal-700 hover:underline dark:text-teal-400">#{{ $detail['parent']['number'] }} {{ $detail['parent']['title'] }}</button>
                        </div>
                    @endif
                    @if (count($detail['children']) > 0)
                        <div>
                            <p class="mb-1 text-xs font-medium uppercase tracking-wider text-slate-500">{{ __('Child tasks') }}</p>
                            <ul class="space-y-1">
                                @foreach ($detail['children'] as $child)
                                    <li><button type="button" wire:click="$set('selected', {{ $child['id'] }})" @class(['text-left hover:underline', 'text-violet-700 dark:text-violet-400' => $child['isKnowledge'], 'text-teal-700 dark:text-teal-400' => ! $child['isKnowledge'], 'line-through opacity-70' => $child['state'] === 'CLOSED'])>#{{ $child['number'] }} {{ $child['title'] }}</button></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if (count($detail['knowledge']) > 0)
                        <div>
                            <p class="mb-1 text-xs font-medium uppercase tracking-wider text-slate-500">{{ __('Related knowledge') }}</p>
                            <ul class="space-y-1">
                                @foreach ($detail['knowledge'] as $item)
                                    <li><button type="button" wire:click="$set('selected', {{ $item['id'] }})" class="text-left text-violet-700 hover:underline dark:text-violet-400">#{{ $item['number'] }} {{ $item['title'] }}</button></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </nav>
            @endif
            @if ($detail['issue']->projectItems->contains(fn ($membership) => $membership->priorityOption || $membership->planned_on || $membership->due_on || $membership->repeat_rule))
                <div class="space-y-3">
                    @foreach ($detail['issue']->projectItems as $membership)
                        @continue(! $membership->priorityOption && ! $membership->planned_on && ! $membership->due_on && ! $membership->repeat_rule)
                        <div class="rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-950/60">
                            <p class="font-medium">{{ $membership->project->title }}</p>
                            <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-5 gap-y-2 text-xs">
                                @foreach ([__('Priority') => $membership->priorityOption?->name, __('Planned') => $membership->planned_on?->toDateString(), __('Due') => $membership->due_on?->toDateString(), __('Repeat') => $membership->repeat_rule] as $key => $value)
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
            <section class="border-t border-slate-200 pt-5 dark:border-slate-800">
                <h3 class="mb-4 text-sm font-semibold">{{ __('Discussion & history') }}</h3>
                @forelse ($detail['comments'] as $comment)
                    <article class="mb-5">
                        <div class="mb-2 flex items-center justify-between gap-3"><p class="text-xs text-slate-500"><span class="font-medium text-slate-700 dark:text-slate-300">{{ $comment['author'] ?? __('Unknown author') }}</span> · {{ $comment['date'] }}</p><flux:button type="button" wire:click="beginEditComment({{ $comment['id'] }})" variant="ghost" size="sm">{{ __('Edit') }}</flux:button></div>
                        @if ($editingComment === $comment['id'])
                            <form wire:submit="saveComment" class="space-y-3"><flux:textarea wire:model="editCommentBody" label="{{ __('Comment') }}" rows="5" />@error('editCommentBody')<p role="alert" class="text-sm text-amber-700 dark:text-amber-400">{{ $message }}</p>@enderror<div class="flex justify-end gap-2"><flux:button type="button" wire:click="cancelEditComment" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button><flux:button type="submit" size="sm">{{ __('Save comment') }}</flux:button></div></form>
                        @else <div class="markdown-body text-sm text-slate-700 dark:text-slate-300">{!! $comment['body'] !!}</div>@endif
                    </article>
                @empty<p class="text-sm text-slate-500">{{ __('No saved comments.') }}</p>@endforelse
                <form wire:submit="addComment" class="mt-5 space-y-3"><flux:textarea wire:model="newCommentBody" label="{{ __('Add a comment') }}" rows="4" placeholder="{{ __('Add context, a result, or a follow-up') }}" />@error('newCommentBody')<p role="alert" class="text-sm text-amber-700 dark:text-amber-400">{{ $message }}</p>@enderror@if ($commentError)<p role="alert" class="text-sm text-amber-700 dark:text-amber-400">{{ $commentError }}</p>@endif<div class="flex justify-end"><flux:button type="submit" size="sm">{{ __('Add comment') }}</flux:button></div></form>
            </section>
        </div>
    </section>
</div>
