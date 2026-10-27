<?php

declare(strict_types=1);

use App\Actions\CreateTodoIssue;
use App\Actions\CreateTodoIssues;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\McpWriteReceipt;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;

beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

it('creates independent tasks in order and returns compact summaries without bodies', function (): void {
    $result = app(CreateTodoIssues::class)->handle([
        ['title' => 'First', 'body' => str_repeat('full body ', 1000), 'idempotencyKey' => 'batch-first'],
        ['title' => 'Second'],
    ]);

    expect($result['succeeded'])->toBe(2)->and($result['failed'])->toBe(0)
        ->and(array_column($result['items'], 'index'))->toBe([0, 1])
        ->and(array_column(array_column($result['items'], 'issue'), 'title'))->toBe(['First', 'Second'])
        ->and($result['items'][0]['idempotencyKey'])->toBe('batch-first')
        ->and($result['items'][1]['idempotencyKey'])->toBeNull()
        ->and($result['items'][0]['issue'])->not->toHaveKey('body')
        ->and(Issue::query()->where('title', 'First')->sole()->body)->toBe(str_repeat('full body ', 1000))
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->count())->toBe(2);
});

it('accepts the full twenty-task batch', function (): void {
    $tasks = array_map(fn (int $position): array => ['title' => 'Task '.$position], range(1, 20));
    $result = app(CreateTodoIssues::class)->handle($tasks);
    expect($result['succeeded'])->toBe(20)->and($result['failed'])->toBe(0)
        ->and($result['items'])->toHaveCount(20)->and(Issue::query()->count())->toBe(20);
});

it('keeps good items when payload or business validation fails and reports each error in order', function (): void {
    $result = app(CreateTodoIssues::class)->handle([
        ['title' => 'Good first'], ['title' => ''], ['title' => 'Bad area', 'area' => 999_999], ['title' => 'Good last'],
    ]);

    expect($result['succeeded'])->toBe(2)->and($result['failed'])->toBe(2)
        ->and($result['items'][0]['issue']['title'])->toBe('Good first')
        ->and($result['items'][1]['error']['code'])->toBe('validation_error')
        ->and($result['items'][1]['error']['fields'])->toHaveKey('title')
        ->and($result['items'][2]['error']['message'])->toContain('area is no longer available')
        ->and($result['items'][3]['issue']['title'])->toBe('Good last')
        ->and(Issue::query()->count())->toBe(2);
});

it('rolls back a failed item including new labels Groups queue entries and its receipt', function (): void {
    $project = GitHubProject::factory()->create();
    ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);

    $result = app(CreateTodoIssues::class)->handle([
        ['title' => 'Before'],
        ['title' => 'Rolled back', 'area' => $project->id, 'newGroupName' => 'Not saved', 'labelNames' => ['not saved'], 'addLinks' => [['issueId' => 999_999]], 'idempotencyKey' => 'rollback-item'],
        ['title' => 'After'],
    ]);

    expect($result['failed'])->toBe(1)->and($result['succeeded'])->toBe(2)
        ->and($result['items'][1]['error']['code'])->toBe('validation_error')
        ->and(Issue::query()->pluck('title')->all())->toBe(['Before', 'After'])
        ->and(Label::query()->count())->toBe(0)->and(ProjectFieldOption::query()->count())->toBe(0)
        ->and(McpWriteReceipt::query()->where('idempotency_key', 'rollback-item')->exists())->toBeFalse()
        ->and(GitHubPushQueueItem::query()->count())->toBe(2);
});

it('retries keyed successes without duplicates and can fix a previously failed keyed item', function (): void {
    $tasks = [['title' => 'Once', 'idempotencyKey' => 'retry-a'], ['title' => 'Fix me', 'area' => 999_999, 'idempotencyKey' => 'retry-b']];
    $first = app(CreateTodoIssues::class)->handle($tasks);
    unset($tasks[1]['area']);
    $retry = app(CreateTodoIssues::class)->handle($tasks);

    expect($first['failed'])->toBe(1)->and($retry['failed'])->toBe(0)
        ->and($retry['items'][0]['issue']['id'])->toBe($first['items'][0]['issue']['id'])
        ->and(Issue::query()->count())->toBe(2)->and(McpWriteReceipt::query()->count())->toBe(2)
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->count())->toBe(2);
});

it('replays an existing keyed child even if its parent is no longer available', function (): void {
    $parent = app(CreateTodoIssue::class)->handle(title: 'Original parent');
    $tasks = [['title' => 'Original child', 'parentId' => $parent->id, 'idempotencyKey' => 'original-child']];
    $first = app(CreateTodoIssues::class)->handle($tasks);
    $parent->update(['is_available' => false]);
    $retry = app(CreateTodoIssues::class)->handle($tasks);

    expect($retry['failed'])->toBe(0)->and($retry['items'][0]['issue']['id'])->toBe($first['items'][0]['issue']['id'])
        ->and(Issue::query()->count())->toBe(2);
});

it('uses existing parent defaults and supports the normal labels links and step fields', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $parent = app(CreateTodoIssue::class)->handle(title: 'Parent', area: $project->id, groupId: $group->id);
    $target = app(CreateTodoIssue::class)->handle(title: 'Linked record');

    $result = app(CreateTodoIssues::class)->handle([['title' => 'Child', 'parentId' => $parent->id, 'area' => null, 'groupId' => null,
        'labelNames' => ['agent task'], 'newLabelName' => 'bug', 'addLinks' => [['issueId' => $target->id]], 'step' => ['doneWhen' => 'It works']]]);
    $child = Issue::query()->findOrFail($result['items'][0]['issue']['id']);

    expect($result['items'][0]['issue']['area']['id'])->toBe($project->id)
        ->and($result['items'][0]['issue']['group'])->toBe($group->name)
        ->and($child->labels->pluck('name')->all())->toEqualCanonicalizing(['agent task', 'bug'])
        ->and($child->done_when)->toBe('It works')->and($child->links()->count())->toBe(1);
});

it('requires parent IDs to exist before the batch even if a guessed ID is created by an earlier item', function (): void {
    $nextId = (int) Issue::query()->max('id') + 1;
    $result = app(CreateTodoIssues::class)->handle([['title' => 'New task'], ['title' => 'Not a batch child', 'parentId' => $nextId]]);

    expect($result['items'][0]['issue']['id'])->toBe($nextId)
        ->and($result['items'][1]['error']['message'])->toContain('before this batch')
        ->and(Issue::query()->count())->toBe(1);
});

it('returns per-item validation errors for malformed tasks and unsupported fields', function (mixed $bad): void {
    $result = app(CreateTodoIssues::class)->handle([$bad, ['title' => 'Still created']]);
    expect($result['items'][0]['error']['code'])->toBe('validation_error')
        ->and($result['items'][1]['issue']['title'])->toBe('Still created')
        ->and($result['failed'])->toBe(1)->and($result['succeeded'])->toBe(1);
})->with(['string' => ['bad'], 'null' => [null], 'unsupported parent reference' => [['title' => 'Bad', 'parentIndex' => 0]], 'bad step' => [['title' => 'Bad', 'step' => ['unknown' => 'x']]]]);

it('rejects invalid batch envelopes without creating any tasks', function (array $tasks): void {
    expect(fn () => app(CreateTodoIssues::class)->handle($tasks))->toThrow(TodoValidationException::class);
    expect(Issue::query()->count())->toBe(0);
})->with(['empty' => [[]], 'too many' => [array_fill(0, 21, ['title' => 'No writes'])], 'not a list' => [['task' => ['title' => 'No writes']]]]);

it('surfaces unexpected errors instead of disguising them as per-item validation failures', function (): void {
    $this->mock(CreateTodoIssue::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('Unexpected implementation failure')));
    expect(fn () => app(CreateTodoIssues::class)->handle([['title' => 'Task']]))->toThrow(RuntimeException::class, 'Unexpected implementation failure');
});

it('withholds untrusted text on a replay and reports an unavailable replay without duplicating it', function (): void {
    $issue = Issue::factory()->for(GitHubRepository::query()->sole(), 'repository')->create(['author_login' => 'untrusted-outsider', 'title' => 'Secret untrusted title']);
    McpWriteReceipt::query()->create(['idempotency_key' => 'existing-record', 'subject_type' => 'issue', 'subject_id' => $issue->id]);
    $tasks = [['title' => 'Replay', 'idempotencyKey' => 'existing-record']];

    $result = app(CreateTodoIssues::class)->handle($tasks);
    expect($result['items'][0]['issue']['withheld'])->toBeTrue()->and(json_encode($result))->not->toContain('Secret untrusted title');
    $issue->update(['is_available' => false]);
    $unavailable = app(CreateTodoIssues::class)->handle($tasks);
    expect($unavailable['items'][0]['error']['code'])->toBe('unavailable')->and(Issue::query()->count())->toBe(1);
});
