<?php

declare(strict_types=1);

use App\Actions\ClearActivityLog;
use App\Actions\CountRelatedActivity;
use App\Actions\DescribeActivityFilterOptions;
use App\Actions\ListActivityLog;
use App\Models\ChangeLog;
use App\Models\McpCallLog;
use App\Support\ActivityLog;
use App\Support\ActivityLogFilters;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    /** Only ever changed through setLog(): a client-writable value here would let a tampered request pick the log Clear empties. */
    #[Locked]
    public string $log = ActivityLog::MCP_CALLS;

    #[Locked]
    public int $page = 1;

    /**
     * Where the user was before following a request link, newest last, so Back can return there.
     *
     * @var list<array{log: string, from: string, to: string, type: string, status: string, category: string, source: string, search: string, requestId: string, page: int, expanded: int|null}>
     */
    #[Locked]
    public array $history = [];

    public string $from = '';

    public string $to = '';

    public string $type = '';

    public string $status = '';

    public string $category = '';

    public string $source = '';

    public string $search = '';

    public string $requestId = '';

    public ?int $expanded = null;

    public string $message = '';

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return ['from.date_format' => __('Use a date like 2026-09-21.'), 'to.date_format' => __('Use a date like 2026-09-21.')];
    }

    public function updated(string $property): void
    {
        $this->page = 1;
        $this->expanded = null;
        $this->message = '';

        if (in_array($property, ['from', 'to'], true)) {
            $this->validateOnly($property);
        }
    }

    public function setLog(string $log): void
    {
        if (! $this->isLog($log)) {
            return;
        }

        $this->history = [];
        $this->log = $log;
        $this->clearFilters();
        $this->message = '';
    }

    public function resetFilters(): void
    {
        $this->history = [];
        $this->clearFilters();
    }

    public function showRequest(string $log, string $requestId): void
    {
        if (! $this->isLog($log)) {
            return;
        }

        $cameFrom = $this->snapshot();
        $this->log = $log;
        $this->clearFilters();
        $this->message = '';
        $this->requestId = $requestId;
        $this->history[] = $cameFrom;
    }

    public function goBack(): void
    {
        $previous = array_pop($this->history);

        if ($previous === null) {
            return;
        }

        $this->log = $previous['log'];
        $this->from = $previous['from'];
        $this->to = $previous['to'];
        $this->type = $previous['type'];
        $this->status = $previous['status'];
        $this->category = $previous['category'];
        $this->source = $previous['source'];
        $this->search = $previous['search'];
        $this->requestId = $previous['requestId'];
        $this->page = $previous['page'];
        $this->expanded = $previous['expanded'];
        $this->message = '';
        $this->resetErrorBag();
    }

    public function clearRequest(): void
    {
        $this->requestId = '';
        $this->page = 1;
        $this->expanded = null;
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
        $this->expanded = null;
    }

    public function nextPage(ListActivityLog $list): void
    {
        $this->page = min($this->page + 1, max(1, $list->handle($this->log, $this->filters(), 1, ListActivityLog::DEFAULT_PER_PAGE)->lastPage()));
        $this->expanded = null;
    }

    public function clearLog(ClearActivityLog $clear): void
    {
        $deleted = $clear->handle($this->log);
        $this->resetFilters();
        $this->message = $deleted > 0 ? trans_choice('Cleared :count entry.|Cleared :count entries.', $deleted, ['count' => $deleted]) : '';
    }

    private function isLog(string $log): bool
    {
        return in_array($log, [ActivityLog::MCP_CALLS, ActivityLog::CHANGES], true);
    }

    private function clearFilters(): void
    {
        $this->reset(['from', 'to', 'type', 'status', 'category', 'source', 'search', 'requestId']);
        $this->resetErrorBag();
        $this->page = 1;
        $this->expanded = null;
    }

    /** @return array{log: string, from: string, to: string, type: string, status: string, category: string, source: string, search: string, requestId: string, page: int, expanded: int|null} */
    private function snapshot(): array
    {
        return [
            'log' => $this->log, 'from' => $this->from, 'to' => $this->to, 'type' => $this->type, 'status' => $this->status,
            'category' => $this->category, 'source' => $this->source, 'search' => $this->search, 'requestId' => $this->requestId,
            'page' => $this->page, 'expanded' => $this->expanded,
        ];
    }

    private function filters(): ActivityLogFilters
    {
        return new ActivityLogFilters($this->from, $this->to, $this->type, $this->status, $this->category, $this->source, $this->search, $this->requestId);
    }

    private function hasFilters(): bool
    {
        return trim($this->from.$this->to.$this->type.$this->status.$this->category.$this->source.$this->search.$this->requestId) !== '';
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $list = app(ListActivityLog::class);
        /** @var LengthAwarePaginator<int, McpCallLog|ChangeLog> $entries */
        $entries = $list->handle($this->log, $this->filters(), $this->page);
        $expandedRow = $this->expanded === null ? null : $entries->getCollection()->firstWhere('id', $this->expanded);

        return [
            'entries' => $entries,
            'logTotal' => $this->hasFilters() ? $list->handle($this->log, new ActivityLogFilters, 1, 1)->total() : $entries->total(),
            'hasFilters' => $this->hasFilters(),
            'options' => app(DescribeActivityFilterOptions::class)->handle($this->log),
            'related' => $expandedRow === null ? ['mcp' => 0, 'changes' => 0] : app(CountRelatedActivity::class)->handle((string) $expandedRow->request_id),
            'timezone' => (string) config('dibs.timezone'),
        ];
    }
}; ?>

@php
    $isMcp = $log === 'mcp';
    $logName = $isMcp ? __('MCP calls') : __('Changes');
    $tab = fn (bool $active): string => $active
        ? 'rounded-lg border border-slate-400 bg-slate-200 px-3 py-1.5 font-medium dark:border-slate-500 dark:bg-slate-800'
        : 'rounded-lg border border-transparent bg-slate-100 px-3 py-1.5 transition hover:border-slate-300 dark:bg-slate-900 dark:hover:border-slate-700';
    $field = 'min-h-10 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm dark:border-slate-700 dark:bg-slate-900';
    $show = fn (mixed $value): string => $value === null ? '—' : (is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $statusClass = fn (string $status): string => match ($status) {
        'ok' => 'bg-teal-100 text-teal-900 dark:bg-teal-900/30 dark:text-teal-300',
        'conflict' => 'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-300',
        'error', 'exception' => 'bg-red-100 text-red-900 dark:bg-red-900/30 dark:text-red-300',
        default => 'bg-slate-100 dark:bg-slate-800',
    };
@endphp

<div class="mx-auto max-w-5xl pt-10">
    <p class="text-sm"><a href="{{ route('workspace') }}" class="text-teal-700 hover:underline dark:text-teal-400">{{ __('← Back to tasks') }}</a></p>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight">{{ __('Activity') }}</h1>
    <p class="mt-2 text-slate-600 dark:text-slate-400">{{ __('Every MCP tool call and every recorded data change. Secrets are redacted before they are stored, and old entries are pruned on a schedule.') }}</p>

    @if ($history !== [])
        <p class="mt-4 text-sm"><button type="button" wire:click="goBack" class="text-teal-700 hover:underline dark:text-teal-400">← {{ __('Back to :log', ['log' => $history[array_key_last($history)]['log'] === 'mcp' ? __('MCP calls') : __('Changes')]) }}</button></p>
    @endif

    <div class="mt-6 flex flex-wrap items-center gap-3 text-sm" role="group" aria-label="{{ __('Choose a log') }}">
        <button type="button" wire:click="setLog('mcp')" class="{{ $tab($isMcp) }}" aria-pressed="{{ $isMcp ? 'true' : 'false' }}">{{ __('MCP calls') }}</button>
        <button type="button" wire:click="setLog('changes')" class="{{ $tab(! $isMcp) }}" aria-pressed="{{ $isMcp ? 'false' : 'true' }}">{{ __('Changes') }}</button>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-xs text-slate-500 dark:text-slate-400">{{ __('From') }}
            <input type="date" wire:model.live="from" class="{{ $field }} mt-1" @error('from') aria-invalid="true" @enderror>
            @error('from') <span role="alert" class="mt-1 block text-red-700 dark:text-red-400">{{ $message }}</span> @enderror
        </label>
        <label class="text-xs text-slate-500 dark:text-slate-400">{{ __('To') }}
            <input type="date" wire:model.live="to" class="{{ $field }} mt-1" @error('to') aria-invalid="true" @enderror>
            @error('to') <span role="alert" class="mt-1 block text-red-700 dark:text-red-400">{{ $message }}</span> @enderror
        </label>
        <label class="text-xs text-slate-500 dark:text-slate-400">{{ $isMcp ? __('Tool') : __('Action') }}
            <select wire:model.live="type" class="{{ $field }} mt-1">
                <option value="">{{ __('All') }}</option>
                @foreach ($options['types'] as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>
        @if ($isMcp)
            <label class="text-xs text-slate-500 dark:text-slate-400">{{ __('Status') }}
                <select wire:model.live="status" class="{{ $field }} mt-1">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($options['statuses'] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
        @else
            <label class="text-xs text-slate-500 dark:text-slate-400">{{ __('Category') }}
                <select wire:model.live="category" class="{{ $field }} mt-1">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($options['categories'] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs text-slate-500 dark:text-slate-400">{{ __('Source') }}
                <select wire:model.live="source" class="{{ $field }} mt-1">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($options['sources'] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-2 lg:col-span-4">{{ __('Search') }}
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Tool, action, summary, actor, arguments…') }}" class="{{ $field }} mt-1">
        </label>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
        @if ($requestId !== '')
            <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs dark:bg-slate-800">
                {{ __('Request :id', ['id' => $requestId]) }}
                <button type="button" wire:click="clearRequest" aria-label="{{ __('Remove request filter') }}" class="text-slate-500 hover:text-slate-900 dark:hover:text-slate-100">×</button>
            </span>
        @endif
        @if ($hasFilters)
            <button type="button" wire:click="resetFilters" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs dark:border-slate-700">{{ __('Reset filters') }}</button>
        @endif
        @if ($logTotal > 0)
            <button type="button" wire:click="clearLog" wire:confirm="{{ __('Delete all :count entries in the :log log? This empties the whole log, not just the rows matching your filters.', ['count' => $logTotal, 'log' => $logName]) }}" wire:loading.attr="disabled" class="ml-auto rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-red-700 dark:border-slate-700 dark:text-red-400">{{ $isMcp ? __('Clear all MCP calls') : __('Clear all changes') }}</button>
        @endif
    </div>

    @if ($message !== '')
        <p role="status" class="mt-3 rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-900 dark:bg-teal-950 dark:text-teal-100">{{ $message }}</p>
    @endif

    <p class="mt-4 text-sm text-slate-500 dark:text-slate-400" aria-live="polite">{{ trans_choice(':count entry|:count entries', $entries->total(), ['count' => $entries->total()]) }} · {{ __('Page :page of :last', ['page' => $entries->currentPage(), 'last' => max(1, $entries->lastPage())]) }}</p>

    <ul class="mt-2 border-t border-slate-100 dark:border-slate-900">
        @forelse ($entries as $row)
            @php($at = $row->created_at?->timezone($timezone))
            <li wire:key="entry-{{ $row->id }}" class="border-b border-slate-100 dark:border-slate-900">
                <button type="button" wire:click="toggle({{ $row->id }})" aria-expanded="{{ $expanded === $row->id ? 'true' : 'false' }}" class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-1 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                    @if ($isMcp)
                        <span class="font-mono font-medium">{{ $row->tool }}</span>
                        <span class="rounded px-2 py-0.5 text-xs font-medium {{ $statusClass($row->status) }}">{{ $row->status }}</span>
                        @if ($row->agent_label)<span class="text-slate-500 dark:text-slate-400">{{ $row->agent_label }}</span>@endif
                        @if ($row->duration_ms !== null)<span class="text-slate-500 dark:text-slate-400">{{ $row->duration_ms }} ms</span>@endif
                        <span class="ml-auto text-xs text-slate-500 dark:text-slate-400" title="{{ $at?->format('Y-m-d H:i:s T') }}">{{ $at?->format('M j, H:i:s') }}</span>
                    @else
                        <span class="font-mono font-medium">{{ $row->action }}</span>
                        <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium dark:bg-slate-800">{{ $row->category }}</span>
                        <span class="ml-auto text-xs text-slate-500 dark:text-slate-400" title="{{ $at?->format('Y-m-d H:i:s T') }}">{{ $at?->format('M j, H:i:s') }}</span>
                        <span class="block w-full">{{ $row->summary }}</span>
                        <span class="block w-full text-xs text-slate-500 dark:text-slate-400">{{ $row->actor_label ?? $row->actor_type }} · {{ $row->source }}</span>
                    @endif
                </button>
                @if ($expanded === $row->id)
                    <div class="space-y-3 px-1 pb-3 text-sm">
                        @if ($isMcp)
                            @if ($row->error_message)
                                <p class="rounded bg-red-100 px-3 py-2 text-red-900 dark:bg-red-900/30 dark:text-red-300">{{ $row->error_message }}</p>
                            @endif
                            @if ($row->arguments)
                                <pre class="overflow-x-auto rounded bg-slate-100 p-3 text-xs dark:bg-slate-900">{{ json_encode($row->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            @else
                                <p class="text-slate-500 dark:text-slate-400">{{ __('No arguments.') }}</p>
                            @endif
                        @else
                            @if ($row->subject_label)
                                <p class="text-slate-600 dark:text-slate-300">{{ __('Subject: :subject', ['subject' => $row->subject_label]) }}</p>
                            @endif
                            @if ($row->changes)
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs">
                                        <thead><tr class="border-b border-slate-200 text-slate-500 dark:border-slate-800 dark:text-slate-400"><th class="py-1 pr-3">{{ __('Field') }}</th><th class="py-1 pr-3">{{ __('From') }}</th><th class="py-1">{{ __('To') }}</th></tr></thead>
                                        <tbody>
                                            @foreach ($row->changes as $name => $diff)
                                                <tr class="border-b border-slate-100 align-top dark:border-slate-900"><td class="py-1 pr-3 font-mono">{{ $name }}</td><td class="max-w-xs break-words py-1 pr-3">{{ $show($diff['from'] ?? null) }}</td><td class="max-w-xs break-words py-1">{{ $show($diff['to'] ?? null) }}</td></tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="text-slate-500 dark:text-slate-400">{{ __('No field-level changes recorded.') }}</p>
                            @endif
                        @endif
                        @if ($row->request_id)
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Request :id', ['id' => $row->request_id]) }}</p>
                            @if ($isMcp && $related['changes'] > 0)
                                <button type="button" wire:click="showRequest('changes', '{{ $row->request_id }}')" class="text-teal-700 hover:underline dark:text-teal-400">{{ trans_choice('Show :count change from this request|Show :count changes from this request', $related['changes'], ['count' => $related['changes']]) }}</button>
                            @elseif (! $isMcp && $related['mcp'] > 0)
                                <button type="button" wire:click="showRequest('mcp', '{{ $row->request_id }}')" class="text-teal-700 hover:underline dark:text-teal-400">{{ trans_choice('Show :count MCP call from this request|Show :count MCP calls from this request', $related['mcp'], ['count' => $related['mcp']]) }}</button>
                            @endif
                        @endif
                    </div>
                @endif
            </li>
        @empty
            <li class="py-6 text-center text-slate-500 dark:text-slate-400">{{ $hasFilters ? __('No entries match these filters.') : ($isMcp ? __('No MCP calls recorded yet.') : __('No changes recorded yet.')) }}</li>
        @endforelse
    </ul>

    @if ($entries->lastPage() > 1)
        <div class="mt-4 flex items-center justify-between text-sm">
            <button type="button" wire:click="previousPage" @disabled($entries->currentPage() <= 1) class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-40 dark:border-slate-700">{{ __('Previous') }}</button>
            <button type="button" wire:click="nextPage" @disabled($entries->currentPage() >= $entries->lastPage()) class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-40 dark:border-slate-700">{{ __('Next') }}</button>
        </div>
    @endif
</div>
