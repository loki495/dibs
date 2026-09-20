<?php

declare(strict_types=1);

use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('runs a single pass and exits immediately', function (): void {
    config(['github.token' => 'test-token']);
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null, 'github_number' => null]);
    GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['title' => $issue->title]]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['createIssue' => ['issue' => ['id' => 'I_1', 'number' => 900, 'title' => $issue->title, 'body' => null, 'state' => 'OPEN', 'stateReason' => null, 'url' => null, 'updatedAt' => now()->toIso8601String()]]]], 200));

    $this->artisan('todo:push:drain')->assertExitCode(0);

    expect($issue->refresh()->github_node_id)->toBe('I_1');
});

it('does not pick up a row created after the pass already started counting - the next scheduled tick handles it', function (): void {
    // With sub-minute scheduling (see routes/console.php) a missed row just waits for the next
    // tick, seconds later - no need for this command to loop internally waiting for it.
    config(['github.token' => 'test-token']);
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $second = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null, 'github_number' => null]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['createIssue' => ['issue' => ['id' => 'I_2', 'number' => 901, 'title' => 'x', 'body' => null, 'state' => 'OPEN', 'stateReason' => null, 'url' => null, 'updatedAt' => now()->toIso8601String()]]]], 200));

    $this->artisan('todo:push:drain')->assertExitCode(0);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);

    GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $second->id, 'payload' => ['title' => $second->title]]);
    $this->artisan('todo:push:drain')->assertExitCode(0);
    expect($second->refresh()->github_node_id)->toBe('I_2');
});

it('respects the --limit cap in a single pass', function (): void {
    config(['github.token' => 'test-token']);
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issues = Issue::factory()->count(3)->create(['repository_id' => $repository->id, 'github_node_id' => null, 'github_number' => null]);
    foreach ($issues as $issue) {
        GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['title' => $issue->title]]);
    }
    $sequenceNumber = 900;
    Http::fake(function (Request $request) use (&$sequenceNumber) {
        $sequenceNumber++;

        return Http::response(['data' => ['createIssue' => ['issue' => ['id' => 'I_'.$sequenceNumber, 'number' => $sequenceNumber, 'title' => 'x', 'body' => null, 'state' => 'OPEN', 'stateReason' => null, 'url' => null, 'updatedAt' => now()->toIso8601String()]]]], 200);
    });

    $this->artisan('todo:push:drain', ['--limit' => 1])->assertExitCode(0);

    expect($issues->fresh()->whereNotNull('github_node_id'))->toHaveCount(1);
});

it('fails clearly when no GitHub token is configured', function (): void {
    config(['github.token' => '']);

    $this->artisan('todo:push:drain')->assertExitCode(1);
});
