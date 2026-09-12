<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\ProcessGitHubWebhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JsonException;

class ReceiveGitHubWebhook
{
    public function handle(string $body, string $signature, string $deliveryId, string $event): void
    {
        $secret = (string) config('github.webhook_secret');
        abort_if($secret === '', 503, 'Webhook is not configured.');
        abort_unless(hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature), 401);
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400, 'Invalid JSON.');
        }
        abort_unless(is_array($payload) && ($payload['repository']['full_name'] ?? null) === config('github.owner').'/'.config('github.repository'), 403);
        Validator::make(['delivery' => $deliveryId, 'event' => $event], ['delivery' => ['required', 'string', 'max:255'], 'event' => ['required', 'string', 'max:100']])->validate();
        if (! in_array($event, ['issues', 'issue_comment', 'sub_issues', 'label'], true)) {
            return;
        }
        DB::transaction(function () use ($deliveryId, $event, $body): void {
            $created = DB::table('github_webhook_deliveries')->insertOrIgnore([
                'delivery_id' => $deliveryId, 'event' => $event, 'body_hash' => hash('sha256', $body), 'received_at' => now(),
            ]);
            if ($created) {
                ProcessGitHubWebhook::dispatch($deliveryId)->onConnection('database')->onQueue('github')->beforeCommit();
            }
        });
    }
}
