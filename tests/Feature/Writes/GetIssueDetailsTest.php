<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\GetIssueDetails;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\Label;

it('returns null claim, parent, and empty children/knowledge for a standalone issue', function (): void {
    $issue = Issue::factory()->create();

    $detail = app(GetIssueDetails::class)->handle($issue->id);

    expect($detail['claim'])->toBeNull()
        ->and($detail['parent'])->toBeNull()
        ->and($detail['children'])->toBe([])
        ->and($detail['knowledge'])->toBe([])
        ->and($detail['pushQueuePending'])->toBeFalse();
});

it('includes live claim details for a claimed issue', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    $detail = app(GetIssueDetails::class)->handle($issue->id);

    expect($detail['claim'])->not->toBeNull()
        ->and($detail['claim']['agentName'])->toBe('codex');
});

it('summarizes the parent and children for navigation', function (): void {
    $parent = Issue::factory()->create(['title' => 'Plan issue']);
    $issue = Issue::factory()->for($parent, 'parent')->create();
    $child = Issue::factory()->for($issue, 'parent')->create(['title' => 'Child task']);

    $detail = app(GetIssueDetails::class)->handle($issue->id);

    expect($detail['parent']['title'])->toBe('Plan issue')
        ->and($detail['children'])->toHaveCount(1)
        ->and($detail['children'][0]['title'])->toBe('Child task');
});

it('flags a knowledge-labeled child in the children list, not the sibling knowledge list', function (): void {
    $parent = Issue::factory()->create();
    $issue = Issue::factory()->for($parent, 'parent')->create();
    $knowledgeChild = Issue::factory()->for($issue, 'parent')->create(['title' => 'A lesson learned']);
    $knowledgeChild->labels()->attach(Label::factory()->for($issue->repository, 'repository')->create(['name' => 'lesson']));

    $detail = app(GetIssueDetails::class)->handle($issue->id);

    expect($detail['children'])->toHaveCount(1)
        ->and($detail['children'][0]['isKnowledge'])->toBeTrue()
        ->and($detail['knowledge'])->toBe([]);
});

it('surfaces sibling knowledge issues without duplicating knowledge children', function (): void {
    $parent = Issue::factory()->create();
    $issue = Issue::factory()->for($parent, 'parent')->create();
    $knowledgeSibling = Issue::factory()->for($parent, 'parent')->create(['title' => 'Sibling research']);
    $knowledgeSibling->labels()->attach(Label::factory()->for($issue->repository, 'repository')->create(['name' => 'research']));
    $plainSibling = Issue::factory()->for($parent, 'parent')->create();

    $detail = app(GetIssueDetails::class)->handle($issue->id);

    $titles = array_column($detail['knowledge'], 'title');
    expect($titles)->toContain('Sibling research')
        ->and($titles)->not->toContain($plainSibling->title)
        ->and(count($titles))->toBe(1);
});

it('flags a pending or needs-attention push-queue entry for the issue', function (): void {
    $issue = Issue::factory()->create();
    GitHubPushQueueItem::factory()->create(['target_type' => 'issue', 'target_id' => $issue->id, 'status' => 'needs_attention']);

    $detail = app(GetIssueDetails::class)->handle($issue->id);

    expect($detail['pushQueuePending'])->toBeTrue();
});

it('does not flag a pushed push-queue entry as pending', function (): void {
    $issue = Issue::factory()->create();
    GitHubPushQueueItem::factory()->create(['target_type' => 'issue', 'target_id' => $issue->id, 'status' => 'pushed']);

    $detail = app(GetIssueDetails::class)->handle($issue->id);

    expect($detail['pushQueuePending'])->toBeFalse();
});

it('reports null closing for an open issue', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    expect(app(GetIssueDetails::class)->handle($issue->id)['closing'])->toBeNull();
});

it('reports the reason, rendered note, references and formatted close date for a closed issue', function (): void {
    $issue = Issue::factory()->create(['state' => 'CLOSED', 'state_reason' => 'NOT_PLANNED']);
    $comment = Comment::factory()->for($issue, 'issue')->closing()->create(['body' => 'Turned out to be a duplicate.', 'references' => ['#7']]);

    $closing = app(GetIssueDetails::class)->handle($issue->id)['closing'];

    expect($closing['reason'])->toBe('NOT_PLANNED')
        ->and($closing['note'])->toContain('Turned out to be a duplicate.')
        ->and($closing['references'])->toBe(['#7'])
        ->and($closing['closedAt'])->toBe($comment->created_at->timezone(config('dibs.timezone'))->format('M j, Y'));
});

it('excludes the current closing comment from the ordinary thread, but keeps an earlier one from a prior close', function (): void {
    $issue = Issue::factory()->create(['state' => 'CLOSED']);
    $previousClose = Comment::factory()->for($issue, 'issue')->closing()->create(['body' => 'First close.', 'remote_created_at' => now()->subDay()]);
    $ordinary = Comment::factory()->for($issue, 'issue')->create(['body' => 'Just a remark.', 'remote_created_at' => now()->subHours(2)]);
    $currentClose = Comment::factory()->for($issue, 'issue')->closing()->create(['body' => 'Second close.', 'remote_created_at' => now()]);

    $ids = collect(app(GetIssueDetails::class)->handle($issue->id)['comments'])->pluck('id')->all();

    expect($ids)->toBe([$previousClose->id, $ordinary->id])
        ->and($ids)->not->toContain($currentClose->id);
});

it('shows every comment, including a closing one, in the thread when the issue is open', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $closing = Comment::factory()->for($issue, 'issue')->closing()->create();

    expect(collect(app(GetIssueDetails::class)->handle($issue->id)['comments'])->pluck('id')->all())->toBe([$closing->id]);
});
