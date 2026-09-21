<?php

declare(strict_types=1);

use App\Actions\SearchTodoIssues;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Support\IssueFilters;
use Illuminate\Validation\ValidationException;

it('finds tasks plans and closed knowledge by title and body with all terms required', function (): void {
    $task = Issue::factory()->create(['title' => 'Queue retries', 'body' => 'Retry failed deliveries']);
    $plan = Issue::factory()->create(['title' => 'Delivery plan', 'body' => 'Improve queue retries']);
    $plan->labels()->attach(Label::factory()->create(['name' => 'plan']));
    $knowledge = Issue::factory()->create(['title' => 'Delivery lesson', 'body' => 'Queue retries explained', 'state' => 'CLOSED', 'parent_issue_id' => $plan->id]);
    $knowledge->labels()->attach(Label::factory()->create(['name' => 'lesson']));
    Issue::factory()->create(['title' => 'Queue only', 'body' => null]);
    Issue::factory()->create(['title' => 'Queue retries hidden', 'is_available' => false]);

    $result = app(SearchTodoIssues::class)->handle(query: '  QUEUE   retries  ');

    expect($result['total'])->toBe(3)
        ->and(array_column($result['items'], 'id'))->toBe([$task->id, $plan->id, $knowledge->id])
        ->and($result['items'][2])->toMatchArray(['knowledge' => true, 'parentId' => $plan->id, 'labels' => ['lesson']]);
});

it('matches terms across fields and provides bounded excerpts around body matches', function (): void {
    $issue = Issue::factory()->create(['title' => 'Queue design', 'body' => str_repeat('éarlier context ', 70).'Retries avoid losing work. '.str_repeat('later context ', 70)]);
    $result = app(SearchTodoIssues::class)->handle(query: 'queue retries');
    $item = $result['items'][0];

    expect($item['id'])->toBe($issue->id)
        ->and($item['excerpt'])->toContain('Retries avoid losing work')
        ->and(mb_strlen($item['excerpt']))->toBeLessThanOrEqual(242)
        ->and($item)->not->toHaveKey('body');
});

it('treats SQL wildcard characters as literal search text', function (string $query): void {
    $match = Issue::factory()->create(['title' => 'Literal '.$query, 'body' => null]);
    Issue::factory()->create(['title' => 'Unrelated text', 'body' => 'Everything else']);

    $result = app(SearchTodoIssues::class)->handle(query: $query);

    expect(array_column($result['items'], 'id'))->toBe([$match->id]);
})->with(['%', '_', '\\', "' OR 1=1 --"]);

it('filters by area group label and state', function (): void {
    $match = Issue::factory()->create(['title' => 'Searchable', 'state' => 'CLOSED']);
    $label = Label::factory()->create(['name' => 'decision']);
    $match->labels()->attach($label);
    $membership = ProjectItem::factory()->for($match, 'issue')->create();
    Issue::factory()->create(['title' => 'Searchable']);

    $result = app(SearchTodoIssues::class)->handle(query: 'searchable', area: $membership->project_id, label: 'decision', state: 'CLOSED');
    expect(array_column($result['items'], 'id'))->toBe([$match->id]);
    expect(app(SearchTodoIssues::class)->handle(query: 'searchable', group: 999999)['total'])->toBe(0);
    $membership->update(['is_available' => false]);
    expect(app(SearchTodoIssues::class)->handle(query: 'searchable', area: $membership->project_id)['total'])->toBe(0);
});

it('paginates equal ranked matches deterministically and returns empty results', function (): void {
    $issues = Issue::factory()->count(3)->create(['title' => 'Matching title']);
    $result = app(SearchTodoIssues::class)->handle(query: 'matching', page: 2, perPage: 1);

    expect($result)->toMatchArray(['page' => 2, 'perPage' => 1, 'total' => 3, 'lastPage' => 3])
        ->and(array_column($result['items'], 'id'))->toBe([$issues[1]->id])
        ->and(app(SearchTodoIssues::class)->handle(query: 'absentterm')['items'])->toBe([]);
});

it('requires area and group to match the same available membership', function (): void {
    $issue = Issue::factory()->create(['title' => 'Unique topic', 'body' => null]);
    $first = ProjectItem::factory()->for($issue, 'issue')->create();
    $group = ProjectFieldOption::factory()->create();
    $second = ProjectItem::factory()->for($issue, 'issue')->create(['group_option_id' => $group->id]);
    $search = app(SearchTodoIssues::class);

    expect($search->handle(query: 'unique', group: $group->id)['total'])->toBe(1)
        ->and($search->handle(query: 'unique', area: $first->project_id, group: $group->id)['total'])->toBe(0)
        ->and($search->handle(query: 'unique', area: $second->project_id, group: $group->id)['total'])->toBe(1);
    $second->project->update(['is_available' => false]);
    expect($search->handle(query: 'unique', group: $group->id)['total'])->toBe(0);
});

it('returns empty excerpts for absent bodies and caps page size', function (): void {
    Issue::factory()->create(['title' => 'Needle', 'body' => null]);
    $result = app(SearchTodoIssues::class)->handle(query: 'needle', perPage: 1000);

    expect($result['items'][0]['excerpt'])->toBe('')
        ->and($result['perPage'])->toBe(50);
});

it('rejects empty or oversized queries at the action boundary', function (string $query): void {
    expect(fn () => app(SearchTodoIssues::class)->handle(query: $query))
        ->toThrow(ValidationException::class, 'Provide a non-empty search query');
})->with(['', " \t\n", str_repeat('a', 201)]);

it('searches by filters alone when no query is given, listing in id order with the start of each body', function (): void {
    $first = Issue::factory()->create(['title' => 'First', 'body' => 'Opening words of the first body']);
    $first->labels()->attach(Label::factory()->create(['name' => 'bug']));
    $second = Issue::factory()->create(['title' => 'Second']);
    $second->labels()->attach(Label::query()->where('name', 'bug')->sole());
    Issue::factory()->create(['title' => 'Unlabelled']);

    $result = app(SearchTodoIssues::class)->handle(query: '', filters: new IssueFilters(labels: ['bug']));

    expect(collect($result['items'])->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($result['items'][0]['excerpt'])->toBe('Opening words of the first body')
        ->and($result['total'])->toBe(2);
});

it('treats a blank query as no query when a filter is given, and rejects it when none is', function (): void {
    $issue = Issue::factory()->create();
    $issue->labels()->attach(Label::factory()->create(['name' => 'bug']));

    $withFilter = app(SearchTodoIssues::class)->handle(query: "  \t ", filters: new IssueFilters(labels: ['bug']));

    expect(collect($withFilter['items'])->pluck('id')->all())->toBe([$issue->id])
        ->and(fn () => app(SearchTodoIssues::class)->handle(query: '  ', filters: new IssueFilters))
        ->toThrow(ValidationException::class, 'or at least one filter');
});

it('still rejects an oversized query even when filters are given', function (): void {
    expect(fn () => app(SearchTodoIssues::class)->handle(query: str_repeat('a', 201), filters: new IssueFilters(labels: ['bug'])))
        ->toThrow(ValidationException::class, 'Provide a non-empty search query');
});

it('counts the legacy area, group and label arguments as filters', function (): void {
    $issue = Issue::factory()->create();
    $issue->labels()->attach(Label::factory()->create(['name' => 'bug']));

    expect(collect(app(SearchTodoIssues::class)->handle(query: '', label: 'bug')['items'])->pluck('id')->all())->toBe([$issue->id]);
});
