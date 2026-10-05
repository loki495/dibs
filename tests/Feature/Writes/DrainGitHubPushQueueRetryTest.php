<?php

declare(strict_types=1);

use App\Actions\DrainGitHubPushQueue;
use App\Actions\RetryAllRetriableGitHubPushQueueItems;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // Whole seconds: next_attempt_at is stored without microseconds.
    $this->travelTo(now()->startOfSecond());
    config(['dibs.push_queue' => ['max_attempts' => 8, 'backoff_base_seconds' => 30, 'backoff_cap_seconds' => 3600]]);
    $this->repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $this->queueCreate = function (array $attributes = []): GitHubPushQueueItem {
        $issue = Issue::factory()->create(['repository_id' => $this->repository->id, 'github_node_id' => null, 'github_number' => null, 'title' => 'Push me']);

        return GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['title' => 'Push me', 'body' => null], 'attempts' => 0, ...$attributes]);
    };
    $this->created = ['data' => ['createIssue' => ['issue' => ['id' => 'I_created', 'number' => 7, 'title' => 'Push me', 'body' => null, 'state' => 'OPEN', 'stateReason' => null, 'url' => 'https://github.com/example-owner/example-tasks/issues/7', 'updatedAt' => '2026-10-03T00:00:00Z']]]];
});

it('waits for Retry-After on a rate limit, skips the row until then, and then pushes it', function (): void {
    $item = ($this->queueCreate)();
    Http::fakeSequence()
        ->push(['message' => 'You have exceeded a secondary rate limit.'], 403, ['Retry-After' => '120'])
        ->push($this->created, 200);

    $first = app(DrainGitHubPushQueue::class)->handle('test-token');
    $item->refresh();

    expect($first['deferred'])->toBe(1)
        ->and($item->status)->toBe('pending')
        ->and($item->attempts)->toBe(0)
        ->and($item->next_attempt_at->equalTo(now()->addSeconds(120)))->toBeTrue()
        ->and($item->last_error)->toContain('rate limit');

    $this->travel(119)->seconds();
    expect(app(DrainGitHubPushQueue::class)->handle('test-token'))->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    Http::assertSentCount(1);

    $this->travel(1)->seconds();
    expect(app(DrainGitHubPushQueue::class)->handle('test-token')['pushed'])->toBe(1)
        ->and($item->refresh()->status)->toBe('pushed');
});

it('waits until X-RateLimit-Reset on an exhausted primary limit and holds the other rows without spending their attempts', function (int $status, array $body): void {
    $limited = ($this->queueCreate)();
    $untouched = ($this->queueCreate)();
    $reset = now()->addMinutes(15);
    Http::fake(fn () => Http::response($body, $status, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) $reset->getTimestamp()]));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result['deferred'])->toBe(1)
        ->and($limited->refresh()->attempts)->toBe(0)
        ->and($limited->next_attempt_at->equalTo($reset))->toBeTrue()
        ->and($untouched->refresh()->attempts)->toBe(0)
        ->and($untouched->next_attempt_at->equalTo($reset))->toBeTrue();
    Http::assertSentCount(1);
})->with([
    'GraphQL RATE_LIMITED' => [200, ['errors' => [['type' => 'RATE_LIMITED', 'message' => 'API rate limit exceeded']]]],
    'HTTP 403' => [403, ['message' => 'API rate limit exceeded']],
    'HTTP 429' => [429, ['message' => 'API rate limit exceeded']],
]);

it('never spends the retry budget on rate limits, so a row at its last attempt still waits', function (): void {
    config(['dibs.push_queue.max_attempts' => 3]);
    $item = ($this->queueCreate)(['attempts' => 2]);
    Http::fakeSequence()
        ->push(['message' => 'API rate limit exceeded'], 429, ['Retry-After' => '30'])
        ->push(['message' => 'API rate limit exceeded'], 429, ['Retry-After' => '30'])
        ->push('Bad gateway', 502);

    foreach (range(1, 2) as $pass) {
        app(DrainGitHubPushQueue::class)->handle('test-token');
        $item->refresh();
        expect($item->status)->toBe('pending')->and($item->attempts)->toBe(2);
        $this->travelTo($item->next_attempt_at);
    }
    app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($item->refresh()->status)->toBe('needs_attention')
        ->and($item->attempts)->toBe(3);
});

it('waits a minute on a secondary rate limit that gives no retry time', function (): void {
    $item = ($this->queueCreate)();
    Http::fake(fn () => Http::response(['message' => 'You have exceeded a secondary rate limit.'], 403));

    app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($item->refresh()->next_attempt_at->equalTo(now()->addSeconds(60)))->toBeTrue()
        ->and($item->status)->toBe('pending');
});

it('backs off exponentially on transient failures, up to the cap', function (): void {
    config(['dibs.push_queue.backoff_cap_seconds' => 100]);
    $item = ($this->queueCreate)();
    Http::fake(fn () => Http::response('Bad gateway', 502));

    $delays = [];
    foreach (range(1, 5) as $attempt) {
        app(DrainGitHubPushQueue::class)->handle('test-token');
        $item->refresh();
        $delays[] = (int) now()->diffInSeconds($item->next_attempt_at);
        $this->travelTo($item->next_attempt_at);
    }

    expect($delays)->toBe([30, 60, 100, 100, 100])
        ->and($item->attempts)->toBe(5)
        ->and($item->status)->toBe('pending');
});

it('only marks a row needing attention once the retry budget is spent', function (): void {
    config(['dibs.push_queue.max_attempts' => 3]);
    $item = ($this->queueCreate)();
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $statuses = [];
    foreach (range(1, 3) as $attempt) {
        app(DrainGitHubPushQueue::class)->handle('test-token');
        $item->refresh();
        $statuses[] = $item->status;
        if ($item->next_attempt_at !== null) {
            $this->travelTo($item->next_attempt_at);
        }
    }

    expect($statuses)->toBe(['pending', 'pending', 'needs_attention'])
        ->and($item->attempts)->toBe(3)
        ->and($item->next_attempt_at)->toBeNull()
        ->and($item->last_error)->toContain('connection failed');
});

it('skips rows that are not due yet and attempts the ones that are', function (): void {
    $notDue = ($this->queueCreate)(['next_attempt_at' => now()->addMinute()]);
    $due = ($this->queueCreate)(['next_attempt_at' => now()->subSecond()]);
    Http::fake(fn () => Http::response($this->created, 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result['pushed'])->toBe(1)
        ->and($due->refresh()->status)->toBe('pushed')
        ->and($notDue->refresh()->status)->toBe('pending')
        ->and($notDue->attempts)->toBe(0);
    Http::assertSentCount(1);
});

it('sends a non-transient failure straight to needs attention without retrying', function (int $status, array $body): void {
    $item = ($this->queueCreate)();
    Http::fake(fn () => Http::response($body, $status));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');
    $this->travel(2)->hours();
    app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result['needs_attention'])->toBe(1)
        ->and($item->refresh()->status)->toBe('needs_attention')
        ->and($item->attempts)->toBe(1)
        ->and($item->next_attempt_at)->toBeNull();
    Http::assertSentCount(1);
})->with([
    'HTTP 422' => [422, ['message' => 'Validation Failed']],
    'HTTP 401' => [401, ['message' => 'Bad credentials']],
    'HTTP 403 without a rate limit' => [403, ['message' => 'Resource not accessible by integration']],
    'GraphQL NOT_FOUND' => [200, ['errors' => [['type' => 'NOT_FOUND', 'message' => 'Could not resolve to a node']]]],
]);

it('clears the wait when rows are retried by hand', function (): void {
    $item = ($this->queueCreate)(['status' => 'failed', 'attempts' => 2, 'next_attempt_at' => now()->addHour()]);

    app(RetryAllRetriableGitHubPushQueueItems::class)->handle();

    expect($item->refresh())->status->toBe('pending')->attempts->toBe(0)->next_attempt_at->toBeNull();
});

it('reports a rate limit with the time GitHub gave and at least a minute for the snapshot sync', function (): void {
    Http::fake(fn () => Http::response(['message' => 'secondary rate limit'], 429, ['Retry-After' => '5']));

    try {
        (new GitHubClient('test-token'))->query('query { viewer { login } }');
        $this->fail('Expected a rate-limit exception.');
    } catch (GitHubSyncException $exception) {
        expect($exception->transient)->toBeTrue()
            ->and($exception->isRateLimit())->toBeTrue()
            ->and($exception->retryAt?->equalTo(now()->addSeconds(5)))->toBeTrue()
            ->and($exception->retrySeconds)->toBe(60);
    }
});

it('treats a server error as transient without a GitHub-given retry time', function (): void {
    Http::fake(fn () => Http::response('Service unavailable', 503, ['X-RateLimit-Remaining' => '4000', 'X-RateLimit-Reset' => (string) now()->addHour()->getTimestamp()]));

    try {
        (new GitHubClient('test-token'))->query('query { viewer { login } }');
        $this->fail('Expected a GitHub exception.');
    } catch (GitHubSyncException $exception) {
        expect($exception->transient)->toBeTrue()
            ->and($exception->isRateLimit())->toBeFalse()
            ->and($exception->getMessage())->toBe('GitHub HTTP 503; check access or retry later.');
    }
});
