<?php

declare(strict_types=1);

use App\Actions\DescribeTodoIssue;
use App\Actions\DescribeTodoIssues;
use App\Exceptions\TodoValidationException;
use App\Models\Comment;
use App\Models\Issue;

it('returns ordered details and individual missing or unavailable errors without losing duplicates', function (): void {
    $first = Issue::factory()->create(['body' => 'First body', 'author_login' => null]);
    $removed = Issue::factory()->create(['is_available' => false]);
    $second = Issue::factory()->create(['author_login' => null]);
    $ids = [$second->id, 999_999, $removed->id, $first->id, $second->id];

    $result = app(DescribeTodoIssues::class)->handle($ids);

    expect(array_column($result['items'], 'id'))->toBe($ids)
        ->and($result['items'][0]['issue']['id'])->toBe($second->id)
        ->and($result['items'][1]['error']['code'])->toBe('not_found')
        ->and($result['items'][2]['error']['code'])->toBe('unavailable')
        ->and($result['items'][3]['issue']['body'])->toBe('First body')
        ->and($result['items'][4])->toBe($result['items'][0]);
});

it('applies body and comment options to every selected issue', function (): void {
    $issues = Issue::factory()->count(2)->create(['body' => str_repeat('a', 100), 'author_login' => null]);
    foreach ($issues as $issue) {
        Comment::factory()->for($issue, 'issue')->count(3)->create(['author_login' => null]);
    }

    $result = app(DescribeTodoIssues::class)->handle($issues->modelKeys(), withComments: true, commentsPage: 2, commentsPerPage: 1, maxBodyLength: 10);

    foreach ($result['items'] as $item) {
        expect($item['issue']['body'])->toBe(str_repeat('a', 10).'…')
            ->and($item['issue']['bodyTruncated'])->toBeTrue()
            ->and($item['issue']['comments']['page'])->toBe(2)
            ->and($item['issue']['comments']['items'])->toHaveCount(1);
    }
});

it('shares the total response budget fairly between long unicode bodies', function (): void {
    $issues = Issue::factory()->count(20)->create(['title' => fake()->sentence(), 'url' => null, 'body' => str_repeat('界😀"', 10_000), 'author_login' => null]);

    $result = app(DescribeTodoIssues::class)->handle($issues->modelKeys(), maxResponseChars: 20_000);

    expect(strlen(json_encode($result, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(20_000);
    foreach ($result['items'] as $item) {
        expect($item['issue']['bodyTruncated'])->toBeTrue()
            ->and($item['issue']['body'])->not->toBeEmpty()
            ->and($item['truncatedFields'])->toContain('body');
    }
    expect(max(array_map(fn ($item) => mb_strlen($item['issue']['body']), $result['items']))
        - min(array_map(fn ($item) => mb_strlen($item['issue']['body']), $result['items'])))->toBeLessThanOrEqual(10);
});

it('bounds comments children closing evidence and step fields as well as the issue body', function (): void {
    $issue = Issue::factory()->create(['body' => 'Short', 'state' => 'CLOSED', 'author_login' => null, 'done_when' => str_repeat('Evidence ', 3000)]);
    Issue::factory()->for($issue, 'parent')->count(30)->create(['author_login' => null]);
    Comment::factory()->for($issue, 'issue')->count(10)->create(['body' => str_repeat('Comment ', 3000), 'author_login' => null]);
    Comment::factory()->for($issue, 'issue')->closing()->create(['body' => str_repeat('Closing ', 3000), 'author_login' => null, 'result' => ['docs' => array_fill(0, 1000, str_repeat('path', 100))]]);

    $result = app(DescribeTodoIssues::class)->handle(array_fill(0, 20, $issue->id), withComments: true, maxResponseChars: 20_000);

    expect(strlen(json_encode($result, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(20_000)
        ->and($result['items'])->toHaveCount(20);
    foreach ($result['items'] as $item) {
        expect($item['issue']['id'])->toBe($issue->id)
            ->and([...$item['truncatedFields'], ...$item['omittedFields']])->toContain('comments', 'children', 'closing', 'step');
    }
});

it('withholds untrusted text in batch issue and comment details', function (): void {
    $issue = Issue::factory()->create(['title' => 'Secret issue title', 'body' => 'Secret issue body', 'author_login' => 'untrusted-outsider']);
    Comment::factory()->for($issue, 'issue')->create(['body' => 'Secret comment body', 'author_login' => 'untrusted-outsider']);

    $result = app(DescribeTodoIssues::class)->handle([$issue->id], withComments: true);

    expect($result['items'][0]['issue']['withheld'])->toBeTrue()
        ->and($result['items'][0]['issue']['comments']['items'][0]['withheld'])->toBeTrue()
        ->and(json_encode($result))->not->toContain('Secret');
});

it('preserves full detail when it fits without adding truncation', function (): void {
    $issue = Issue::factory()->create(['author_login' => null]);

    $item = app(DescribeTodoIssues::class)->handle([$issue->id])['items'][0];

    expect($item['issue'])->toBe(app(DescribeTodoIssue::class)->handle($issue->id))
        ->and($item['truncatedFields'])->toBe([])
        ->and($item['omittedFields'])->toBe([]);
});

it('rejects empty or oversized show batches at the action boundary', function (array $ids): void {
    expect(fn () => app(DescribeTodoIssues::class)->handle($ids))->toThrow(TodoValidationException::class, 'Choose between 1 and 20');
})->with([
    'empty' => [[]],
    'oversized' => [range(1, 21)],
]);

it('rejects out of range response budgets at the action boundary', function (int $budget): void {
    expect(fn () => app(DescribeTodoIssues::class)->handle([1], maxResponseChars: $budget))
        ->toThrow(TodoValidationException::class, 'Choose a response budget');
})->with([19_999, 120_001]);
