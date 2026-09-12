<?php

declare(strict_types=1);

use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('runs a single pass and exits immediately with --once', function (): void {
    config(['github.token' => 'test-token']);
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null, 'github_number' => null]);
    GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['title' => $issue->title]]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['createIssue' => ['issue' => ['id' => 'I_1', 'number' => 900, 'title' => $issue->title, 'body' => null, 'state' => 'OPEN', 'stateReason' => null, 'url' => null, 'updatedAt' => now()->toIso8601String()]]]], 200));

    $this->artisan('todo:push:drain', ['--once' => true])->assertExitCode(0);

    expect($issue->refresh()->github_node_id)->toBe('I_1');
});

it('loops within the scheduled slot instead of exiting after one pass', function (): void {
    config(['github.token' => 'test-token']);
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $first = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null, 'github_number' => null]);
    $second = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null, 'github_number' => null]);
    GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $first->id, 'payload' => ['title' => $first->title]]);
    $sequenceNumber = 900;
    Http::fake(function (Request $request) use (&$sequenceNumber) {
        $sequenceNumber++;

        return Http::response(['data' => ['createIssue' => ['issue' => ['id' => 'I_'.$sequenceNumber, 'number' => $sequenceNumber, 'title' => 'x', 'body' => null, 'state' => 'OPEN', 'stateReason' => null, 'url' => null, 'updatedAt' => now()->toIso8601String()]]]], 200);
    });

    // A row created only after the first pass should still be picked up by a later pass within the same run.
    $this->artisan('todo:push:drain', ['--interval' => 1, '--duration' => 2])->assertExitCode(0);
    expect($first->refresh()->github_node_id)->not->toBeNull();

    GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $second->id, 'payload' => ['title' => $second->title]]);
    $this->artisan('todo:push:drain', ['--interval' => 1, '--duration' => 2])->assertExitCode(0);
    expect($second->refresh()->github_node_id)->not->toBeNull();
});

it('fails clearly when no GitHub token is configured', function (): void {
    config(['github.token' => '']);

    $this->artisan('todo:push:drain', ['--once' => true])->assertExitCode(1);
});
