<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessGitHubWebhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReplayGitHubWebhooks extends Command
{
    protected $signature = 'todo:webhooks:replay';

    protected $description = 'Requeue unprocessed GitHub deliveries after fixing credentials or a failed worker';

    public function handle(): int
    {
        foreach (DB::table('github_webhook_deliveries')->whereNull('processed_at')->orderBy('id')->cursor() as $delivery) {
            ProcessGitHubWebhook::dispatch($delivery->delivery_id)->onConnection('database')->onQueue('github');
        }
        $this->info('Unprocessed webhook deliveries queued.');

        return self::SUCCESS;
    }
}
