<?php

declare(strict_types=1);

use App\Actions\DrainGitHubPushQueue;
use App\Actions\RetryGitHubPushQueueItem;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(now()->startOfSecond());
    config(['dibs.push_queue' => ['max_attempts' => 8, 'backoff_base_seconds' => 30, 'backoff_cap_seconds' => 3600]]);
    $this->repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $this->issue = Issue::factory()->create(['repository_id' => $this->repository->id, 'github_node_id' => null, 'github_number' => null, 'title' => 'Push me', 'body' => "Line one\nLine two"]);
    $this->item = GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $this->issue->id, 'payload' => ['title' => 'Push me', 'body' => "Line one\nLine two"], 'attempts' => 0]);
    $this->remoteIssue = fn (string $id, int $number, string $title, string $body, string $author = 'me', ?string $createdAt = null): array => [
        'id' => $id, 'number' => $number, 'title' => $title, 'body' => $body, 'state' => 'OPEN', 'stateReason' => null,
        'url' => 'https://github.com/example-owner/example-tasks/issues/'.$number, 'updatedAt' => now()->toIso8601String(),
        'createdAt' => $createdAt ?? now()->toIso8601String(), 'author' => ['login' => $author],
    ];
    $this->recentIssues = fn (array $nodes, bool $more = false): array => ['data' => ['viewer' => ['login' => 'me'], 'node' => ['issues' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $more]]]]];
    $this->createdIssue = fn (string $id, int $number): array => ['data' => ['createIssue' => ['issue' => ($this->remoteIssue)($id, $number, 'Push me', "Line one\nLine two")]]];
    // Each create mutation answers with the next response in $creates; lookups answer with $lookup.
    // Mutations are counted here because Http::recorded() omits requests that threw.
    $this->mutationCalls = 0;
    $this->fakeGitHub = function (array $creates, ?array $lookup = null, string $mutation = 'createIssue', string $lookupMarker = 'issues(first'): void {
        Http::fake(function (Request $request) use (&$creates, $lookup, $mutation, $lookupMarker) {
            $query = (string) $request['query'];
            if (str_contains($query, $lookupMarker) && $lookup !== null) {
                return Http::response($lookup);
            }
            if (str_contains($query, $mutation)) {
                $this->mutationCalls++;
                $next = array_shift($creates);
                if ($next instanceof Throwable) {
                    throw $next;
                }

                return is_int($next) ? Http::response('Server error', $next) : Http::response($next);
            }

            return Http::response(['errors' => [['message' => 'unexpected request']]], 500);
        });
    };
    $this->drainAgain = function (): void {
        $this->travelTo($this->item->refresh()->next_attempt_at);
        app(DrainGitHubPushQueue::class)->handle('test-token');
    };
});

it('adopts the issue an ambiguous failure created instead of creating a duplicate', function (Throwable|int $failure): void {
    ($this->fakeGitHub)([$failure], ($this->recentIssues)([
        ($this->remoteIssue)('I_other', 40, 'Someone else', 'x', 'someone-else'),
        ($this->remoteIssue)('I_created', 41, 'Push me', "Line one\r\nLine two\n"),
    ]));

    app(DrainGitHubPushQueue::class)->handle('test-token');
    expect($this->item->refresh()->status)->toBe('pending')->and($this->item->unconfirmed_create)->toBeTrue();
    ($this->drainAgain)();

    expect($this->item->refresh()->status)->toBe('pushed')
        ->and($this->issue->refresh()->github_node_id)->toBe('I_created')
        ->and($this->issue->github_number)->toBe(41)
        ->and($this->mutationCalls)->toBe(1);
})->with([
    'timeout' => [new ConnectionException('Operation timed out')],
    'server error' => [502],
]);

it('creates the issue again when the lookup shows the ambiguous failure created nothing', function (): void {
    $alreadyLinked = Issue::factory()->create(['repository_id' => $this->repository->id, 'github_node_id' => 'I_linked', 'title' => 'Push me']);
    ($this->fakeGitHub)(
        [new ConnectionException('Operation timed out'), ($this->createdIssue)('I_new', 42)],
        ($this->recentIssues)([
            ($this->remoteIssue)('I_linked', 39, 'Push me', "Line one\nLine two"),
            ($this->remoteIssue)('I_old', 3, 'Push me', "Line one\nLine two", 'me', now()->subDay()->toIso8601String()),
        ]),
    );

    app(DrainGitHubPushQueue::class)->handle('test-token');
    ($this->drainAgain)();

    expect($this->item->refresh()->status)->toBe('pushed')
        ->and($this->issue->refresh()->github_node_id)->toBe('I_new')
        ->and($alreadyLinked->refresh()->github_node_id)->toBe('I_linked')
        ->and($this->mutationCalls)->toBe(2);
});

it('sends the row to needs attention when it cannot tell whether the ambiguous failure created the issue', function (array $lookup): void {
    ($this->fakeGitHub)([new ConnectionException('Operation timed out')], $lookup);

    app(DrainGitHubPushQueue::class)->handle('test-token');
    ($this->drainAgain)();

    expect($this->item->refresh()->status)->toBe('needs_attention')
        ->and($this->item->last_error)->toContain('may have created this on GitHub anyway')
        ->and($this->item->unconfirmed_create)->toBeFalse()
        ->and($this->issue->refresh()->github_node_id)->toBeNull()
        ->and($this->mutationCalls)->toBe(1);
})->with([
    'an unlinked issue by this token with different content' => [fn () => ($this->recentIssues)([($this->remoteIssue)('I_edited', 41, 'Push me (edited)', 'Different')])],
    'a full page that does not reach back to the queued time' => [fn () => ($this->recentIssues)([($this->remoteIssue)('I_someone', 41, 'Other', 'x', 'someone-else')], more: true)],
]);

it('creates directly when a person retries a row whose earlier create could not be confirmed', function (): void {
    ($this->fakeGitHub)(
        [new ConnectionException('Operation timed out'), ($this->createdIssue)('I_new', 42)],
        ($this->recentIssues)([($this->remoteIssue)('I_edited', 41, 'Push me (edited)', 'Different')]),
    );
    app(DrainGitHubPushQueue::class)->handle('test-token');
    ($this->drainAgain)();

    app(RetryGitHubPushQueueItem::class)->handle($this->item->refresh());
    app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($this->item->refresh()->status)->toBe('pushed')
        ->and($this->issue->refresh()->github_node_id)->toBe('I_new')
        ->and($this->mutationCalls)->toBe(2);
});

it('does not look for an earlier create after a rate limit or a rejection, which GitHub never applied', function (int $status, array $body, array $headers): void {
    Http::fake(fn () => Http::response($body, $status, $headers));

    app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($this->item->refresh()->unconfirmed_create)->toBeFalse();
})->with([
    'rate limit' => [429, ['message' => 'API rate limit exceeded'], ['Retry-After' => '30']],
    'rejection' => [422, ['message' => 'Validation failed'], []],
]);

it('keeps retrying when the lookup itself fails, without creating', function (): void {
    ($this->fakeGitHub)([new ConnectionException('Operation timed out')]);

    app(DrainGitHubPushQueue::class)->handle('test-token');
    ($this->drainAgain)();

    expect($this->item->refresh()->status)->toBe('pending')
        ->and($this->item->attempts)->toBe(2)
        ->and($this->item->unconfirmed_create)->toBeTrue()
        ->and($this->mutationCalls)->toBe(1);
});

describe('comments', function (): void {
    beforeEach(function (): void {
        $this->item->update(['status' => 'pushed']);
        $this->issue->update(['github_node_id' => 'I_parent', 'github_number' => 5]);
        $this->comment = Comment::factory()->create(['issue_id' => $this->issue->id, 'github_node_id' => null, 'body' => 'Checkpoint: done']);
        $this->item = GitHubPushQueueItem::factory()->create(['operation' => 'create_comment', 'target_type' => 'comment', 'target_id' => $this->comment->id, 'payload' => [], 'attempts' => 0]);
        $this->remoteComment = fn (string $id, string $body, string $author = 'me'): array => ['id' => $id, 'body' => $body, 'url' => 'https://github.com/c/'.$id, 'createdAt' => now()->toIso8601String(), 'updatedAt' => now()->toIso8601String(), 'author' => ['login' => $author]];
        $this->recentComments = fn (array $nodes): array => ['data' => ['viewer' => ['login' => 'me'], 'node' => ['comments' => ['nodes' => $nodes, 'pageInfo' => ['hasPreviousPage' => false]]]]];
    });

    it('adopts the comment a timed-out attempt created', function (): void {
        ($this->fakeGitHub)([new ConnectionException('Operation timed out')], ($this->recentComments)([($this->remoteComment)('IC_created', 'Checkpoint: done')]), 'addComment', 'comments(last');

        app(DrainGitHubPushQueue::class)->handle('test-token');
        ($this->drainAgain)();

        expect($this->item->refresh()->status)->toBe('pushed')
            ->and($this->comment->refresh()->github_node_id)->toBe('IC_created')
            ->and($this->mutationCalls)->toBe(1);
    });

    it('comments again when the timed-out attempt left nothing', function (): void {
        ($this->fakeGitHub)(
            [new ConnectionException('Operation timed out'), ['data' => ['addComment' => ['commentEdge' => ['node' => ($this->remoteComment)('IC_new', 'Checkpoint: done')]]]]],
            ($this->recentComments)([($this->remoteComment)('IC_theirs', 'Checkpoint: done', 'someone-else')]),
            'addComment', 'comments(last',
        );

        app(DrainGitHubPushQueue::class)->handle('test-token');
        ($this->drainAgain)();

        expect($this->comment->refresh()->github_node_id)->toBe('IC_new')
            ->and($this->mutationCalls)->toBe(2);
    });

    it('needs attention when an unlinked comment by this token does not match', function (): void {
        ($this->fakeGitHub)([new ConnectionException('Operation timed out')], ($this->recentComments)([($this->remoteComment)('IC_other', 'Something else')]), 'addComment', 'comments(last');

        app(DrainGitHubPushQueue::class)->handle('test-token');
        ($this->drainAgain)();

        expect($this->item->refresh()->status)->toBe('needs_attention')
            ->and($this->comment->refresh()->github_node_id)->toBeNull()
            ->and($this->mutationCalls)->toBe(1);
    });
});

describe('labels', function (): void {
    beforeEach(function (): void {
        $this->item->update(['status' => 'pushed']);
        $this->label = Label::factory()->create(['repository_id' => $this->repository->id, 'github_node_id' => null, 'name' => 'next', 'color' => '6B7280']);
        $this->item = GitHubPushQueueItem::factory()->create(['operation' => 'create_label', 'target_type' => 'label', 'target_id' => $this->label->id, 'payload' => ['name' => 'next', 'color' => '6B7280', 'description' => null], 'attempts' => 0]);
    });

    it('adopts the label a timed-out attempt created, found by its unique name', function (): void {
        ($this->fakeGitHub)([new ConnectionException('Operation timed out')], ['data' => ['node' => ['label' => ['id' => 'LA_created', 'name' => 'next', 'color' => '6B7280', 'description' => null, 'url' => 'https://github.com/l']]]], 'createLabel', 'label(name');

        app(DrainGitHubPushQueue::class)->handle('test-token');
        ($this->drainAgain)();

        expect($this->label->refresh()->github_node_id)->toBe('LA_created')
            ->and($this->mutationCalls)->toBe(1);
    });

    it('creates the label again when no label has its name', function (): void {
        ($this->fakeGitHub)(
            [new ConnectionException('Operation timed out'), ['data' => ['createLabel' => ['label' => ['id' => 'LA_new', 'name' => 'next', 'color' => '6B7280', 'description' => null, 'url' => 'https://github.com/l']]]]],
            ['data' => ['node' => ['label' => null]]], 'createLabel', 'label(name',
        );

        app(DrainGitHubPushQueue::class)->handle('test-token');
        ($this->drainAgain)();

        expect($this->label->refresh()->github_node_id)->toBe('LA_new')
            ->and($this->mutationCalls)->toBe(2);
    });
});

it('adopts a Group option an earlier attempt already added instead of adding a second one', function (): void {
    $this->item->update(['status' => 'pushed']);
    $field = ProjectField::factory()->for(GitHubProject::factory()->create(), 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => null, 'name' => 'New Group']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_group_option', 'target_type' => 'project_field_option', 'target_id' => $option->id, 'payload' => ['name' => 'New Group', 'color' => 'GRAY']]);
    Http::fake(fn () => Http::response(['data' => ['node' => ['id' => 'F_group', 'options' => [['id' => 'O_new', 'name' => 'new group', 'color' => 'GRAY', 'description' => '']]]]]));

    app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($item->refresh()->status)->toBe('pushed')
        ->and($option->refresh()->github_option_id)->toBe('O_new')
        ->and(Http::recorded(fn (Request $request): bool => str_contains((string) $request['query'], 'updateProjectV2Field')))->toBeEmpty();
});
