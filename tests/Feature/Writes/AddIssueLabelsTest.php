<?php

declare(strict_types=1);

use App\Actions\AddIssueLabels;
use App\Models\Issue;
use App\Models\Label;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('adds selected labels in GitHub before attaching them locally', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_task']);
    $first = Label::factory()->for($issue->repository, 'repository')->create(['github_node_id' => 'LA_next']);
    $second = Label::factory()->for($issue->repository, 'repository')->create(['github_node_id' => 'LA_waiting']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['addLabelsToLabelable' => ['labelable' => ['id' => 'I_task']]]], 200));

    $updated = app(AddIssueLabels::class)->handle('test-token', $issue, [$first, $second]);

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'addLabelsToLabelable')
        && (array) $request->data()['variables'] === ['labelableId' => 'I_task', 'labelIds' => ['LA_next', 'LA_waiting']]);
    expect($updated->labels->pluck('id')->all())->toContain($first->id, $second->id);
});

it('refuses labels from another repository before contacting GitHub', function (): void {
    Http::fake();
    $issue = Issue::factory()->create();
    $foreign = Label::factory()->create();

    expect(fn () => app(AddIssueLabels::class)->handle('test-token', $issue, [$foreign]))
        ->toThrow(GitHubSyncException::class, 'same repository');
    Http::assertNothingSent();
});
