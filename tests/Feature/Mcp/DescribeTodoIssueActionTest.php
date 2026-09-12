<?php

declare(strict_types=1);

use App\Actions\DescribeTodoIssue;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;

it('describes an issue with its body, hierarchy, and memberships', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'dotfiles']);
    $parent = Issue::factory()->create(['title' => 'Website parent']);
    $issue = Issue::factory()->for($parent, 'parent')->create(['title' => 'Update config', 'body' => 'Detailed body']);
    $child = Issue::factory()->for($issue, 'parent')->create();
    ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['group_option_id' => $group->id]);
    $issue->labels()->attach(Label::factory()->create(['name' => 'next']));

    $result = app(DescribeTodoIssue::class)->handle($issue->id);

    expect($result['id'])->toBe($issue->id)
        ->and($result['body'])->toBe('Detailed body')
        ->and($result['labels'])->toBe(['next'])
        ->and($result['parent']['id'])->toBe($parent->id)
        ->and($result['children'])->toHaveCount(1)
        ->and($result['children'][0]['id'])->toBe($child->id)
        ->and($result['memberships'])->toHaveCount(1)
        ->and($result['memberships'][0]['area']['title'])->toBe('Personal Projects')
        ->and($result['memberships'][0]['group'])->toBe('dotfiles')
        ->and($result)->not->toHaveKey('comments');
});

it('includes paginated comments only when requested', function (): void {
    $issue = Issue::factory()->create();
    Comment::factory()->for($issue, 'issue')->count(3)->sequence(fn ($sequence) => ['remote_created_at' => now()->addMinutes($sequence->index)])->create();

    $withoutComments = app(DescribeTodoIssue::class)->handle($issue->id);
    $withComments = app(DescribeTodoIssue::class)->handle($issue->id, withComments: true, commentsPerPage: 2);

    expect($withoutComments)->not->toHaveKey('comments')
        ->and($withComments['comments']['items'])->toHaveCount(2)
        ->and($withComments['comments']['total'])->toBe(3)
        ->and($withComments['comments']['lastPage'])->toBe(2);
});

it('excludes unavailable comments', function (): void {
    $issue = Issue::factory()->create();
    Comment::factory()->for($issue, 'issue')->create(['is_available' => false]);

    $result = app(DescribeTodoIssue::class)->handle($issue->id, withComments: true);

    expect($result['comments']['items'])->toHaveCount(0);
});

it('throws a distinct not-found error for a nonexistent local id', function (): void {
    expect(fn () => app(DescribeTodoIssue::class)->handle(999_999))
        ->toThrow(TodoRecordNotFoundException::class);
});

it('throws a distinct unavailable error for a soft-removed issue', function (): void {
    $issue = Issue::factory()->create(['is_available' => false]);

    expect(fn () => app(DescribeTodoIssue::class)->handle($issue->id))
        ->toThrow(TodoRecordUnavailableException::class);
});
