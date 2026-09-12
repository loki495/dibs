<?php

declare(strict_types=1);

use App\Actions\DescribeGitHubPushQueue;
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

    public function mount(): void
    {
        if (! config('todo.push_queue_ui_enabled')) {
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

    private function refresh(): void
    {
        $rows = GitHubPushQueueItem::query()->orderByDesc('id')->limit(200)->get();
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
    }
}; ?>

<div class="mx-auto max-w-5xl pt-10">
    <p class="text-sm"><a href="{{ route('workspace') }}" class="text-teal-700 hover:underline dark:text-teal-400">{{ __('← Back to tasks') }}</a></p>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight">{{ __('GitHub push queue') }}</h1>
    <p class="mt-2 text-slate-600 dark:text-slate-400">{{ __('Local SQLite is already the confirmed result for each of these. This page only tracks delivery to GitHub.') }}</p>

    <div class="mt-6 flex flex-wrap gap-3 text-sm">
        <span class="rounded-lg bg-slate-100 px-3 py-1.5 dark:bg-slate-900">{{ __('Pending') }}: {{ $pendingCount }}</span>
        <span class="rounded-lg bg-amber-100 px-3 py-1.5 text-amber-900 dark:bg-amber-900/30 dark:text-amber-300">{{ __('Failed') }}: {{ $failedCount }}</span>
        <span class="rounded-lg bg-red-100 px-3 py-1.5 text-red-900 dark:bg-red-900/30 dark:text-red-300">{{ __('Needs attention') }}: {{ $needsAttentionCount }}</span>
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
                            @if (in_array($item['status'], ['failed', 'needs_attention'], true))
                                <button type="button" wire:click="retry({{ $item['id'] }})" wire:loading.attr="disabled" class="rounded-lg border border-slate-300 px-2 py-1 text-xs dark:border-slate-700">{{ __('Retry') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-center text-slate-500 dark:text-slate-400">{{ __('Nothing queued.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
