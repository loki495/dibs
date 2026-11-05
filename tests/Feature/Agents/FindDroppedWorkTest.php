<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\FindDroppedWork;
use App\Models\Issue;
use App\Models\TaskClaim;
use App\Support\IssueFilters;
use Illuminate\Support\Facades\Artisan;

function lapse(Issue $issue, array $paths = []): TaskClaim
{
    $claim = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30, $paths)['claim'];
    $claim->update(['expires_at' => now()->subMinute()]);

    return $claim;
}

it('lists an open task whose claim lapsed unreleased, temporary ones included', function (): void {
    $plain = Issue::factory()->create();
    $temp = Issue::factory()->create(['is_temporary' => true]);
    lapse($plain);
    lapse($temp);

    $items = app(FindDroppedWork::class)->handle()['items'];

    expect(collect($items)->pluck('issueId')->sort()->values()->all())->toBe([$plain->id, $temp->id])
        ->and($items[0]['how'])->toBe('lapsed');
});

it('skips live claims, released claims, closed tasks and tasks re-claimed since', function (): void {
    $live = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($live, 'codex', getmypid(), 30);
    $released = Issue::factory()->create();
    lapse($released)->update(['released_at' => now(), 'end_reason' => TaskClaim::END_RELEASED]);
    $closed = Issue::factory()->create(['state' => 'CLOSED']);
    lapse($closed);
    $retaken = Issue::factory()->create();
    lapse($retaken);
    app(ClaimTaskForAgent::class)->handle($retaken, 'claude', getmypid(), 30);

    expect(app(FindDroppedWork::class)->handle()['items'])->toBe([]);
});

it('lists a task a human released as abandoned and filters by path', function (): void {
    $abandoned = Issue::factory()->create();
    $claim = lapse($abandoned, ['/srv/a']);
    $claim->update(['released_at' => now(), 'end_reason' => TaskClaim::END_ABANDONED]);
    $other = Issue::factory()->create();
    lapse($other, ['/srv/b']);

    $items = app(FindDroppedWork::class)->handle('/srv/a/sub')['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['issueId'])->toBe($abandoned->id)
        ->and($items[0]['how'])->toBe('abandoned');
});

it('prints the list as json from the CLI', function (): void {
    $issue = Issue::factory()->create();
    lapse($issue);

    $exit = Artisan::call('todo:agent:dropped');

    expect($exit)->toBe(0)
        ->and(json_decode(Artisan::output(), true)['items'][0]['issueId'])->toBe($issue->id);
});

it('scopes the list to a parent and its descendants', function (): void {
    $plan = Issue::factory()->create();
    $child = Issue::factory()->create(['parent_issue_id' => $plan->id]);
    $grandchild = Issue::factory()->create(['parent_issue_id' => $child->id]);
    $elsewhere = Issue::factory()->create();
    foreach ([$child, $grandchild, $elsewhere] as $issue) {
        lapse($issue);
    }

    $direct = app(FindDroppedWork::class)->handle(filters: new IssueFilters(parentId: $plan->id))['items'];
    $tree = app(FindDroppedWork::class)->handle(filters: new IssueFilters(parentId: $plan->id, descendants: true))['items'];

    expect(collect($direct)->pluck('issueId')->all())->toBe([$child->id])
        ->and(collect($tree)->pluck('issueId')->sort()->values()->all())->toBe([$child->id, $grandchild->id]);
});
