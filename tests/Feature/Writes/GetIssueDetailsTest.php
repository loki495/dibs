<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\GetIssueDetails;
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

it('surfaces knowledge-labeled children and siblings without duplicates', function (): void {
    $parent = Issue::factory()->create();
    $issue = Issue::factory()->for($parent, 'parent')->create();
    $knowledgeChild = Issue::factory()->for($issue, 'parent')->create(['title' => 'A lesson learned']);
    $knowledgeChild->labels()->attach(Label::factory()->for($issue->repository, 'repository')->create(['name' => 'lesson']));
    $knowledgeSibling = Issue::factory()->for($parent, 'parent')->create(['title' => 'Sibling research']);
    $knowledgeSibling->labels()->attach(Label::factory()->for($issue->repository, 'repository')->create(['name' => 'research']));
    $plainSibling = Issue::factory()->for($parent, 'parent')->create();

    $detail = app(GetIssueDetails::class)->handle($issue->id);

    $titles = array_column($detail['knowledge'], 'title');
    expect($titles)->toContain('A lesson learned', 'Sibling research')
        ->and($titles)->not->toContain($plainSibling->title)
        ->and(count($titles))->toBe(2);
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
