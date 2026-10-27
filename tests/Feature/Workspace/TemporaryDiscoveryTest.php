<?php

declare(strict_types=1);

use App\Actions\BuildIssueTree;
use App\Actions\DescribeTodoIssue;
use App\Actions\FindNextPlanStep;
use App\Actions\ListTodoIssues;
use App\Actions\SearchTodoIssues;
use App\Models\Issue;
use App\Models\User;
use Livewire\Livewire;

it('keeps temporary tasks discoverable and marked while excluding them from durable workspace counts', function (): void {
    $parent = Issue::factory()->create();
    $temporary = Issue::factory()->create(['parent_issue_id' => $parent->id, 'is_temporary' => true]);
    $detail = app(DescribeTodoIssue::class)->handle($parent->id);
    $tree = app(BuildIssueTree::class)->handle();

    expect($detail['children'][0]['temporary'])->toBeTrue()
        ->and(collect($tree['rows'])->firstWhere('id', $temporary->id)['temporary'])->toBeTrue()
        ->and($tree['taskCount'])->toBe(1);
});

it('offers temporary children of a temporary plan as next steps', function (): void {
    $plan = Issue::factory()->create(['is_temporary' => true]);
    $step = Issue::factory()->create(['parent_issue_id' => $plan->id, 'is_temporary' => true]);

    expect(app(FindNextPlanStep::class)->handle($plan->id)['ready']['step'])
        ->toMatchArray(['id' => $step->id, 'temporary' => true]);
});

it('marks temporary tasks in list, search and the workspace, and closes the detail after consuming one', function (): void {
    $issue = Issue::factory()->create(['title' => 'Temporary discovery example', 'is_temporary' => true]);

    expect(collect(app(ListTodoIssues::class)->handle()['items'])->firstWhere('id', $issue->id)['temporary'])->toBeTrue()
        ->and(collect(app(SearchTodoIssues::class)->handle(query: 'discovery')['items'])->firstWhere('id', $issue->id)['temporary'])->toBeTrue();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->assertSee('Temporary')->set('selected', $issue->id)->assertSee('Temporary')
        ->call('closeIssue')->assertSet('selected', 0);
    expect(Issue::query()->find($issue->id))->toBeNull();
});

it('keeps a permanent step actionable when it only has temporary children', function (): void {
    $plan = Issue::factory()->create();
    $step = Issue::factory()->create(['parent_issue_id' => $plan->id]);
    Issue::factory()->create(['parent_issue_id' => $step->id, 'is_temporary' => true]);

    expect(app(FindNextPlanStep::class)->handle($plan->id)['ready']['step']['id'])->toBe($step->id);
});

it('describes permanent temporary-task deletion and requires children to be consumed first', function (): void {
    $parent = Issue::factory()->temporary()->create();
    $child = Issue::factory()->temporary()->create(['parent_issue_id' => $parent->id]);
    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('selected', $parent->id)->call('openDeleteConfirm')
        ->assertSee('Consume all children before deleting this temporary task.')
        ->assertDontSee('Move them up a level')
        ->call('confirmDelete', false)->assertSet('deleteConfirmOpen', true)
        ->assertSet('deleteError', 'Consume all children before deleting a temporary task.');
    $component->set('selected', $child->id)->call('openDeleteConfirm')
        ->assertSee('This permanently deletes the temporary task. It cannot be restored.')
        ->call('confirmDelete', false)->assertSet('selected', 0);
    expect($parent->fresh())->not->toBeNull()
        ->and(Issue::find($child->id))->toBeNull();
});
