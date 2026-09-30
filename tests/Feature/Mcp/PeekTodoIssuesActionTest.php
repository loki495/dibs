<?php

declare(strict_types=1);

use App\Actions\PeekTodoIssues;
use App\Models\Issue;

it('reports id, title, revision and state for each existing issue, in the order requested', function (): void {
    $a = Issue::factory()->create(['title' => 'First', 'revision' => 3, 'state' => 'OPEN']);
    $b = Issue::factory()->create(['title' => 'Second', 'revision' => 1, 'state' => 'CLOSED']);

    $result = app(PeekTodoIssues::class)->handle([$b->id, $a->id]);

    expect($result['items'])->toBe([
        ['id' => $b->id, 'title' => 'Second', 'revision' => 1, 'state' => 'CLOSED', 'available' => true],
        ['id' => $a->id, 'title' => 'First', 'revision' => 3, 'state' => 'OPEN', 'available' => true],
    ])->and($result['unresolved'])->toBe([]);
});

it('still reports a deleted issue, marked unavailable, instead of hiding it', function (): void {
    $issue = Issue::factory()->create(['title' => 'Gone', 'state' => 'CLOSED', 'revision' => 2, 'is_available' => false]);

    $result = app(PeekTodoIssues::class)->handle([$issue->id]);

    expect($result['items'])->toBe([
        ['id' => $issue->id, 'title' => 'Gone', 'revision' => 2, 'state' => 'CLOSED', 'available' => false],
    ]);
});

it('lists an id with no matching issue under unresolved instead of failing the whole call', function (): void {
    $issue = Issue::factory()->create();

    $result = app(PeekTodoIssues::class)->handle([$issue->id, 999999]);

    expect($result['items'])->toHaveCount(1)->and($result['items'][0]['id'])->toBe($issue->id)
        ->and($result['unresolved'])->toBe([999999]);
});

it('de-duplicates a repeated id, keeping only its first position', function (): void {
    $a = Issue::factory()->create();
    $b = Issue::factory()->create();

    $result = app(PeekTodoIssues::class)->handle([$a->id, $b->id, $a->id]);

    expect($result['items'])->toHaveCount(2)
        ->and(array_column($result['items'], 'id'))->toBe([$a->id, $b->id]);
});

it('returns nothing but an empty result for an all-unresolved batch', function (): void {
    $result = app(PeekTodoIssues::class)->handle([999999, 999998]);

    expect($result['items'])->toBe([])->and($result['unresolved'])->toBe([999999, 999998]);
});
