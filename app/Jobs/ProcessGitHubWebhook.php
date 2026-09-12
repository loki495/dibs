<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SyncGitHub;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessGitHubWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 840;

    public function __construct(public readonly string $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 120, 300, 900];
    }

    public function handle(SyncGitHub $sync): void
    {
        $delivery = DB::table('github_webhook_deliveries')->where('delivery_id', $this->deliveryId)->first();
        if ($delivery === null || $delivery->processed_at !== null) {
            return;
        }
        $token = (string) config('github.token');
        if ($token === '') {
            throw new GitHubSyncException('Webhook processing requires a server-side GitHub token.');
        }
        $sync->handle($token, comments: true);
        DB::table('github_webhook_deliveries')->where('delivery_id', $this->deliveryId)->update(['processed_at' => now()]);
    }
}
