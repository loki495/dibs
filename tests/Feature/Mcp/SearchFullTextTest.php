<?php

declare(strict_types=1);

use App\Actions\SearchTodoIssues;
use App\Models\Comment;
use App\Models\Issue;

function searchIds(string $query): array
{
    return array_column(app(SearchTodoIssues::class)->handle(query: $query)['items'], 'id');
}

it('finds an issue through the text of one of its comments', function (): void {
    $issue = Issue::factory()->create(['title' => 'Unrelated title', 'body' => 'Nothing here']);
    Issue::factory()->create(['title' => 'Other', 'body' => 'Nothing here']);
    Comment::factory()->for($issue)->create(['body' => 'Rotated the zebracorn credentials']);

    expect(searchIds('zebracorn'))->toBe([$issue->id]);
});

it('matches word stems and word prefixes', function (): void {
    $issue = Issue::factory()->create(['title' => 'Webhooks retrying', 'body' => null]);

    expect(searchIds('retries'))->toBe([$issue->id])
        ->and(searchIds('webh'))->toBe([$issue->id])
        ->and(searchIds('retried webhook'))->toBe([$issue->id]);
});

it('keeps the index current when an issue or comment is edited or deleted', function (): void {
    $issue = Issue::factory()->create(['title' => 'Alpha', 'body' => 'first']);
    $comment = Comment::factory()->for($issue)->create(['body' => 'commentword']);

    $issue->update(['title' => 'Bravo']);
    $comment->update(['body' => 'otherword']);

    expect(searchIds('alpha'))->toBe([])
        ->and(searchIds('bravo'))->toBe([$issue->id])
        ->and(searchIds('commentword'))->toBe([])
        ->and(searchIds('otherword'))->toBe([$issue->id]);

    $comment->delete();
    expect(searchIds('otherword'))->toBe([]);
    $issue->delete();
    expect(searchIds('bravo'))->toBe([]);
});

it('ranks the issue where a rare term is concentrated above a long rambling one', function (): void {
    $rambling = Issue::factory()->create(['title' => 'Notes', 'body' => str_repeat('filler words about many topics ', 40).'search']);
    $focused = Issue::factory()->create(['title' => 'Notes', 'body' => 'search search search']);

    expect(searchIds('search'))->toBe([$focused->id, $rambling->id]);
});

it('does not match comments of unavailable comments or issues', function (): void {
    $hidden = Issue::factory()->create(['title' => 'Hidden', 'is_available' => false]);
    Comment::factory()->for($hidden)->create(['body' => 'quokkaword']);
    $visible = Issue::factory()->create(['title' => 'Visible']);
    Comment::factory()->for($visible)->create(['body' => 'quokkaword', 'is_available' => false]);

    expect(searchIds('quokkaword'))->toBe([]);
});

it('survives queries made of search-syntax characters', function (string $query): void {
    Issue::factory()->create(['title' => 'Plain title', 'body' => null]);

    expect(fn () => searchIds($query))->not->toThrow(Throwable::class);
})->with(['"unbalanced', 'a AND', 'NEAR(', 'title*', '-minus', 'col:val', '(paren']);
