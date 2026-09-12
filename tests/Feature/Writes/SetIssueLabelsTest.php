<?php

declare(strict_types=1);

use App\Actions\SetIssueLabels;
use App\Models\Issue;
use App\Models\Label;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('adds only new labels in GitHub before replacing the local projection', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_task']);
    $new = Label::factory()->for($issue->repository, 'repository')->create(['github_node_id' => 'LA_new']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['addLabelsToLabelable' => ['labelable' => ['id' => 'I_task']]]], 200));

    $updated = app(SetIssueLabels::class)->handle('test-token', $issue, [$new]);

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'addLabelsToLabelable')
        && (array) $request->data()['variables'] === ['labelableId' => 'I_task', 'labelIds' => ['LA_new']]);
    expect($updated->labels->pluck('id')->all())->toBe([$new->id]);
});

it('removes only labels no longer selected after GitHub confirms it', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_task']);
    $old = Label::factory()->for($issue->repository, 'repository')->create(['github_node_id' => 'LA_old']);
    $issue->labels()->attach($old);
    Http::fake(fn (Request $request) => Http::response(['data' => ['removeLabelsFromLabelable' => ['labelable' => ['id' => 'I_task']]]], 200));

    $updated = app(SetIssueLabels::class)->handle('test-token', $issue, []);

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'removeLabelsFromLabelable')
        && (array) $request->data()['variables'] === ['labelableId' => 'I_task', 'labelIds' => ['LA_old']]);
    expect($updated->labels)->toHaveCount(0);
});
