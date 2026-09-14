<?php

declare(strict_types=1);

use App\Actions\ClearPushedGitHubPushQueueItems;
use App\Actions\DescribeGitHubPushQueue;
use App\Actions\DiscardAllNeedsAttentionGitHubPushQueueItems;
use App\Actions\DiscardGitHubPushQueueItem;
use App\Actions\RetryAllRetriableGitHubPushQueueItems;
use App\Actions\RetryGitHubPushQueueItem;
use App\Models\GitHubPushQueueItem;
use Livewire\Component;

new class extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    public int $pendingCount = 0;

    public int $failedCount = 0;

    public int $needsAttentionCount = 0;

    public int $pushedCount = 0;

    public string $statusFilter = 'all';

    public function mount(): void
    {
        if (! config('dibs.push_queue_ui_enabled')) {
            abort(404);
        }
        $this->refresh();
    }

    public function retry(int $id, RetryGitHubPushQueueItem $retry): void
    {
        $item = GitHubPushQueueItem::query()->find($id);
        if ($item instanceof GitHubPushQueueItem) {
            $retry->handle($item);
        }
        $this->refresh();
    }

    public function discard(int $id, DiscardGitHubPushQueueItem $discard): void
    {
        $item = GitHubPushQueueItem::query()->find($id);
        if ($item instanceof GitHubPushQueueItem) {
            $discard->handle($item);
        }
        $this->refresh();
    }

    public function clearPushed(ClearPushedGitHubPushQueueItems $clear): void
    {
        $clear->handle();
        $this->refresh();
    }

    public function retryAllRetriable(RetryAllRetriableGitHubPushQueueItems $retryAll): void
    {
        $retryAll->handle();
        $this->refresh();
    }

    public function discardAllNeedsAttention(DiscardAllNeedsAttentionGitHubPushQueueItems $discardAll): void
    {
        $discardAll->handle();
        $this->refresh();
    }

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $this->statusFilter === $status ? 'all' : $status;
        $this->refresh();
    }

    private function refresh(): void
    {
        $rows = GitHubPushQueueItem::query()->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByDesc('id')->limit(200)->get();
        $targets = app(DescribeGitHubPushQueue::class)->describeTargets($rows);
        $this->items = $rows->map(fn (GitHubPushQueueItem $row): array => [
            'id' => $row->id,
            'operation' => $row->operation,
            'target' => $targets[$row->id] ?? $row->target_type.' #'.$row->target_id,
            'status' => $row->status,
            'attempts' => $row->attempts,
            'last_error' => $row->last_error,
            'queued_at' => $row->created_at?->diffForHumans(),
            'attempted_at' => $row->attempted_at?->diffForHumans(),
        ])->all();
        $counts = app(DescribeGitHubPushQueue::class)->counts();
        $this->pendingCount = $counts['pending'];
        $this->failedCount = $counts['failed'];
        $this->needsAttentionCount = $counts['needsAttention'];
        $this->pushedCount = $counts['pushed'];
    }
}; ?>

<div class="mx-auto max-w-5xl pt-10">
    <p class="text-sm"><a href="{{ route('workspace') }}" class="text-teal-700 hover:underline dark:text-teal-400">{{ __('← Back to tasks') }}</a></p>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight">{{ __('GitHub push queue') }}</h1>
    <p class="mt-2 text-slate-600 dark:text-slate-400">{{ __('Local SQLite is already the confirmed result for each of these. This page only tracks delivery to GitHub.') }}</p>

    <div class="mt-6 flex flex-wrap items-center gap-3 text-sm" aria-label="{{ __('Filter by status') }}">
        <button type="button" wire:click="setStatusFilter('all')" @class(['rounded-lg px-3 py-1.5 border transition', 'border-slate-400 bg-slate-200 font-medium dark:border-slate-500 dark:bg-slate-800' => $statusFilter === 'all', 'border-transparent bg-slate-100 hover:border-slate-300 dark:bg-slate-900 dark:hover:border-slate-700' => $statusFilter !== 'all']) aria-pressed="{{ $statusFilter === 'all' ? 'true' : 'false' }}">{{ __('All') }}: {{ $pendingCount + $failedCount + $needsAttentionCount + $pushedCount }}</button>
        <button type="button" wire:click="setStatusFilter('pending')" @class(['rounded-lg px-3 py-1.5 border transition', 'border-slate-400 bg-slate-200 font-medium dark:border-slate-500 dark:bg-slate-800' => $statusFilter === 'pending', 'border-transparent bg-slate-100 hover:border-slate-300 dark:bg-slate-900 dark:hover:border-slate-700' => $statusFilter !== 'pending']) aria-pressed="{{ $statusFilter === 'pending' ? 'true' : 'false' }}">{{ __('Pending') }}: {{ $pendingCount }}</button>
        <button type="button" wire:click="setStatusFilter('failed')" @class(['rounded-lg px-3 py-1.5 border text-amber-900 transition dark:text-amber-300', 'border-amber-500 bg-amber-200 font-medium dark:border-amber-600 dark:bg-amber-900/50' => $statusFilter === 'failed', 'border-transparent bg-amber-100 hover:border-amber-300 dark:bg-amber-900/30 dark:hover:border-amber-700' => $statusFilter !== 'failed']) aria-pressed="{{ $statusFilter === 'failed' ? 'true' : 'false' }}">{{ __('Failed') }}: {{ $failedCount }}</button>
        <button type="button" wire:click="setStatusFilter('needs_attention')" @class(['rounded-lg px-3 py-1.5 border text-red-900 transition dark:text-red-300', 'border-red-500 bg-red-200 font-medium dark:border-red-600 dark:bg-red-900/50' => $statusFilter === 'needs_attention', 'border-transparent bg-red-100 hover:border-red-300 dark:bg-red-900/30 dark:hover:border-red-700' => $statusFilter !== 'needs_attention']) aria-pressed="{{ $statusFilter === 'needs_attention' ? 'true' : 'false' }}">{{ __('Needs attention') }}: {{ $needsAttentionCount }}</button>
        <button type="button" wire:click="setStatusFilter('pushed')" @class(['rounded-lg px-3 py-1.5 border text-teal-900 transition dark:text-teal-300', 'border-teal-500 bg-teal-200 font-medium dark:border-teal-600 dark:bg-teal-900/50' => $statusFilter === 'pushed', 'border-transparent bg-teal-100 hover:border-teal-300 dark:bg-teal-900/30 dark:hover:border-teal-700' => $statusFilter !== 'pushed']) aria-pressed="{{ $statusFilter === 'pushed' ? 'true' : 'false' }}">{{ __('Pushed') }}: {{ $pushedCount }}</button>
        @if ($pushedCount > 0)
            <button type="button" wire:click="clearPushed" wire:confirm="{{ __('Clear all :count pushed rows? They already reached GitHub — this only removes their local history.', ['count' => $pushedCount]) }}" wire:loading.attr="disabled" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs dark:border-slate-700">{{ __('Clear pushed') }}</button>
        @endif
        @if ($failedCount + $needsAttentionCount > 0)
            <button type="button" wire:click="retryAllRetriable" wire:confirm="{{ __('Retry all :count failed/needs-attention rows?', ['count' => $failedCount + $needsAttentionCount]) }}" wire:loading.attr="disabled" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs dark:border-slate-700">{{ __('Retry all retriable') }}</button>
        @endif
        @if ($needsAttentionCount > 0)
            <button type="button" wire:click="discardAllNeedsAttention" wire:confirm="{{ __('Discard all :count needs-attention rows? They will never be pushed to GitHub.', ['count' => $needsAttentionCount]) }}" wire:loading.attr="disabled" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-red-700 dark:border-slate-700 dark:text-red-400">{{ __('Discard stuck rows') }}</button>
        @endif
    </div>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <th class="py-2 pr-4">{{ __('Operation') }}</th>
                    <th class="py-2 pr-4">{{ __('Target') }}</th>
                    <th class="py-2 pr-4">{{ __('Status') }}</th>
                    <th class="py-2 pr-4">{{ __('Attempts') }}</th>
                    <th class="py-2 pr-4">{{ __('Last error') }}</th>
                    <th class="py-2 pr-4">{{ __('Queued') }}</th>
                    <th class="py-2 pr-4"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr wire:key="queue-{{ $item['id'] }}" class="border-b border-slate-100 dark:border-slate-900">
                        <td class="py-2 pr-4">{{ $item['operation'] }}</td>
                        <td class="max-w-xs truncate py-2 pr-4" title="{{ $item['target'] }}">{{ $item['target'] }}</td>
                        <td class="py-2 pr-4">
                            <span @class([
                                'rounded px-2 py-0.5 text-xs font-medium',
                                'bg-slate-100 dark:bg-slate-800' => $item['status'] === 'pending',
                                'bg-teal-100 text-teal-900 dark:bg-teal-900/30 dark:text-teal-300' => $item['status'] === 'pushed',
                                'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-300' => $item['status'] === 'failed',
                                'bg-red-100 text-red-900 dark:bg-red-900/30 dark:text-red-300' => $item['status'] === 'needs_attention',
                            ])>{{ $item['status'] }}</span>
                        </td>
                        <td class="py-2 pr-4">{{ $item['attempts'] }}</td>
                        <td class="max-w-xs truncate py-2 pr-4" title="{{ $item['last_error'] }}">{{ $item['last_error'] }}</td>
                        <td class="py-2 pr-4">{{ $item['queued_at'] }}</td>
                        <td class="py-2 pr-4">
                            <div class="flex gap-2">
                                @if (in_array($item['status'], ['failed', 'needs_attention'], true))
                                    <button type="button" wire:click="retry({{ $item['id'] }})" wire:loading.attr="disabled" class="rounded-lg border border-slate-300 px-2 py-1 text-xs dark:border-slate-700">{{ __('Retry') }}</button>
                                @endif
                                <button type="button" wire:click="discard({{ $item['id'] }})" wire:confirm="{{ $item['status'] === 'pushed' ? __('Remove this row from the queue history?') : __('Discard this change? It will never be pushed to GitHub.') }}" wire:loading.attr="disabled" class="rounded-lg border border-slate-300 px-2 py-1 text-xs text-red-700 dark:border-slate-700 dark:text-red-400">{{ __('Discard') }}</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-center text-slate-500 dark:text-slate-400">{{ __('Nothing queued.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
