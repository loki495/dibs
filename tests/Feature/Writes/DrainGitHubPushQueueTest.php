<?php

declare(strict_types=1);

use App\Actions\DrainGitHubPushQueue;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('pushes a pending create_issue row and stamps the local issue with its GitHub identity', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null, 'github_number' => null, 'title' => 'Capture this']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['title' => 'Capture this', 'body' => null]]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['createIssue' => ['issue' => ['id' => 'I_created', 'number' => 500, 'title' => 'Capture this', 'body' => null, 'state' => 'OPEN', 'stateReason' => null, 'url' => 'https://github.com/loki495/Todo/issues/500', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($issue->refresh())->github_node_id->toBe('I_created')->github_number->toBe(500);
    expect($item->refresh())->status->toBe('pushed')->pushed_at->not->toBeNull();
});

it('defers a row for retry when GitHub is unreachable, without touching the local issue', function (): void {
    $repository = GitHubRepository::factory()->create();
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null, 'github_number' => null]);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $issue->id, 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($issue->refresh()->github_node_id)->toBeNull();
    expect($item->refresh())->status->toBe('pending')->attempts->toBe(1)->last_error->not->toBeNull();
});

it('marks a row needing attention after repeated failures instead of retrying forever', function (): void {
    $repository = GitHubRepository::factory()->create();
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null, 'github_number' => null]);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $issue->id, 'attempts' => 2, 'status' => 'failed']);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'still failing']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    expect($item->refresh())->status->toBe('needs_attention')->attempts->toBe(3);
});

it('rejects an unsupported operation instead of looping on it forever', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'delete_issue']);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    expect($item->refresh()->status)->toBe('needs_attention');
    Http::assertNothingSent();
});

it('reconciles a row already pushed by a previous run instead of creating a duplicate issue', function (): void {
    $repository = GitHubRepository::factory()->create();
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_already', 'github_number' => 501]);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $issue->id]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh()->status)->toBe('pushed');
    Http::assertNothingSent();
});

it('creates a Group option on GitHub, merges the returned option list, and stamps only the matching local row', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'data_type' => 'SINGLE_SELECT', 'github_node_id' => 'F_group']);
    $existingOption = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_existing', 'name' => 'Existing', 'position' => 0]);
    $pendingOption = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => null, 'name' => 'New Group', 'position' => 1]);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_group_option', 'target_type' => 'project_field_option', 'target_id' => $pendingOption->id, 'payload' => ['name' => 'New Group', 'color' => 'GRAY']]);
    Http::fake([
        '*' => Http::sequence()
            ->push(['data' => ['node' => ['id' => 'F_group', 'options' => [['id' => 'O_existing', 'name' => 'Existing', 'color' => 'BLUE', 'description' => '']]]]])
            ->push(['data' => ['updateProjectV2Field' => ['projectV2Field' => ['id' => 'F_group', 'options' => [
                ['id' => 'O_existing', 'name' => 'Existing', 'color' => 'BLUE', 'description' => ''],
                ['id' => 'O_new', 'name' => 'New Group', 'color' => 'GRAY', 'description' => ''],
            ]]]]]),
    ]);

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($pendingOption->refresh())->github_option_id->toBe('O_new')->position->toBe(1);
    expect($existingOption->refresh())->github_option_id->toBe('O_existing')->position->toBe(0);
    expect(ProjectFieldOption::query()->count())->toBe(2);
    expect($item->refresh()->status)->toBe('pushed');
});

it('creates a label on GitHub and stamps it with the created identity', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $label = Label::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null, 'name' => 'next', 'color' => '0f766e']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_label', 'target_type' => 'label', 'target_id' => $label->id, 'payload' => ['name' => 'next', 'color' => '0f766e', 'description' => null]]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['createLabel' => ['label' => ['id' => 'L_created', 'name' => 'next', 'color' => '0f766e', 'description' => '', 'url' => 'https://github.com/loki495/Todo/labels/next']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($label->refresh())->github_node_id->toBe('L_created')->name->toBe('next')->color->toBe('0f766e');
    expect($item->refresh())->status->toBe('pushed')->pushed_at->not->toBeNull();
});

it('defers creating a label when GitHub cannot be reached', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $label = Label::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null, 'name' => 'next']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_label', 'target_type' => 'label', 'target_id' => $label->id, 'attempts' => 0, 'payload' => ['name' => 'next', 'color' => '0f766e', 'description' => null]]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('adds a project membership on GitHub and stamps it with the created identity', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => null]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'add_project_membership', 'target_type' => 'project_item', 'target_id' => $item->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['addProjectV2ItemById' => ['item' => ['id' => 'PI_created', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->github_node_id->toBe('PI_created')->content_type->toBe('ISSUE');
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('waits when the project membership cannot be added until the issue is pushed', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $issue = Issue::factory()->create(['github_node_id' => null]);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => null]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'add_project_membership', 'target_type' => 'project_item', 'target_id' => $item->id, 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    expect($queueItem->refresh())->attempts->toBe(0)->status->toBe('pending')->last_error->toBeNull();
    Http::assertNothingSent();
});

it('sets a project item group on GitHub after the item and option are pushed', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_group']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'group_option_id' => $option->id]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_project_item_group', 'target_type' => 'project_item', 'target_id' => $item->id, 'payload' => ['group_option_id' => $option->id]]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['updateProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PI_test', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->group_option_id->toBe($option->id);
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('adds issue labels on GitHub and timestamps the issue', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $label1 = Label::factory()->for($repository, 'repository')->create(['github_node_id' => 'L_test1']);
    $label2 = Label::factory()->for($repository, 'repository')->create(['github_node_id' => 'L_test2']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'add_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['label_ids' => [$label1->id, $label2->id]]]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['addLabelsToLabelable' => ['labelable' => ['id' => 'I_test']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('waits to add labels until all labels are pushed', function (): void {
    $repository = GitHubRepository::factory()->create();
    $label1 = Label::factory()->for($repository, 'repository')->create(['github_node_id' => 'L_test1']);
    $label2 = Label::factory()->for($repository, 'repository')->create(['github_node_id' => null]);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'add_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['label_ids' => [$label1->id, $label2->id]], 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    expect($queueItem->refresh())->attempts->toBe(0)->status->toBe('pending')->last_error->toBeNull();
    Http::assertNothingSent();
});

it('sets a child issue parent on GitHub after both issues are pushed', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $parent = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_parent']);
    $child = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_child']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_parent', 'target_type' => 'issue', 'target_id' => $child->id, 'payload' => ['parent_issue_id' => $parent->id]]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['addSubIssue' => ['subIssue' => ['id' => 'I_child', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($child->refresh())->github_parent_node_id->toBe('I_parent');
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('waits to set issue parent until the child is pushed', function (): void {
    $repository = GitHubRepository::factory()->create();
    $parent = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_parent']);
    $child = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_parent', 'target_type' => 'issue', 'target_id' => $child->id, 'payload' => ['parent_issue_id' => $parent->id], 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    expect($queueItem->refresh())->attempts->toBe(0)->status->toBe('pending')->last_error->toBeNull();
    Http::assertNothingSent();
});

it('updates an issue body on GitHub', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test', 'title' => 'Old title', 'body' => 'Old body']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'update_issue_body', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['title' => 'New title', 'body' => 'New body']]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['updateIssue' => ['issue' => ['id' => 'I_test', 'title' => 'New title', 'body' => 'New body', 'state' => 'OPEN', 'stateReason' => null, 'url' => 'https://github.com/loki495/Todo/issues/1', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($issue->refresh())->title->toBe('New title')->body->toBe('New body');
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('waits to update issue body until the issue is pushed', function (): void {
    $repository = GitHubRepository::factory()->create();
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'update_issue_body', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['title' => 'New', 'body' => null], 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    expect($queueItem->refresh())->attempts->toBe(0)->status->toBe('pending')->last_error->toBeNull();
    Http::assertNothingSent();
});

it('closes an issue on GitHub', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test', 'state' => 'OPEN']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'close_issue', 'target_type' => 'issue', 'target_id' => $issue->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['closeIssue' => ['issue' => ['id' => 'I_test', 'state' => 'CLOSED', 'stateReason' => 'COMPLETED', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($issue->refresh())->state->toBe('CLOSED')->state_reason->toBe('COMPLETED');
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('deletes an issue on GitHub', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test', 'is_available' => false]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'delete_issue', 'target_type' => 'issue', 'target_id' => $issue->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['deleteIssue' => ['clientMutationId' => null]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'deleteIssue')
        && (array) $request->data()['variables'] === ['issueId' => 'I_test']);
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('waits to delete an issue until its own creation has reached GitHub', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => null, 'is_available' => false]);
    GitHubPushQueueItem::factory()->create(['operation' => 'delete_issue', 'target_type' => 'issue', 'target_id' => $issue->id]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('gives up deleting an issue that unexpectedly still has available children locally', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test', 'is_available' => false]);
    Issue::factory()->for($issue, 'parent')->for($repository, 'repository')->create();
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'delete_issue', 'target_type' => 'issue', 'target_id' => $issue->id]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
    expect($queueItem->refresh())->status->toBe('needs_attention');
});

it('deletes a project membership on GitHub', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'is_available' => true]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'delete_project_item', 'target_type' => 'project_item', 'target_id' => $item->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['deleteProjectV2Item' => ['deletedItemId' => 'PI_test']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->is_available->toBe(false);
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('clears a project item group on GitHub', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_group']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'group_option_id' => $option->id]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'clear_project_item_group', 'target_type' => 'project_item', 'target_id' => $item->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['clearProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PI_test', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->group_option_id->toBeNull();
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('sets issue labels on GitHub by adding and removing', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $label1 = Label::factory()->for($repository, 'repository')->create(['github_node_id' => 'L_test1']);
    $label2 = Label::factory()->for($repository, 'repository')->create(['github_node_id' => 'L_test2']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['add_label_ids' => [$label2->id], 'remove_label_ids' => [$label1->id]]]);
    Http::fake([
        '*' => Http::sequence()
            ->push(['data' => ['addLabelsToLabelable' => ['labelable' => ['id' => 'I_test']]]])
            ->push(['data' => ['removeLabelsFromLabelable' => ['labelable' => ['id' => 'I_test']]]]),
    ]);

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('removes a parent issue link on GitHub', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $parent = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_parent']);
    $child = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_child']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'remove_issue_parent', 'target_type' => 'issue', 'target_id' => $child->id, 'payload' => ['parent_github_node_id' => 'I_parent']]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['removeSubIssue' => ['subIssue' => ['id' => 'I_child', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('creates a comment on GitHub', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $comment = Comment::factory()->create(['issue_id' => $issue->id, 'github_node_id' => null, 'body' => 'New comment']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'create_comment', 'target_type' => 'comment', 'target_id' => $comment->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['addComment' => ['commentEdge' => ['node' => ['id' => 'C_created', 'body' => 'New comment', 'url' => 'https://github.com/loki495/Todo/issues/1#comment-123', 'createdAt' => '2026-09-11T20:00:00Z', 'updatedAt' => '2026-09-11T20:00:00Z', 'author' => ['login' => 'testuser']]]]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($comment->refresh())->github_node_id->toBe('C_created')->author_login->toBe('testuser');
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('waits to create a comment until the issue is pushed', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => null]);
    $comment = Comment::factory()->create(['issue_id' => $issue->id, 'github_node_id' => null]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'create_comment', 'target_type' => 'comment', 'target_id' => $comment->id, 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    expect($queueItem->refresh())->attempts->toBe(0)->status->toBe('pending')->last_error->toBeNull();
    Http::assertNothingSent();
});

it('updates a comment on GitHub', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $comment = Comment::factory()->create(['issue_id' => $issue->id, 'github_node_id' => 'C_test', 'body' => 'Updated text']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'update_comment', 'target_type' => 'comment', 'target_id' => $comment->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['updateIssueComment' => ['issueComment' => ['id' => 'C_test', 'body' => 'Updated text', 'url' => 'https://github.com/loki495/Todo/issues/1#comment-123', 'createdAt' => '2026-09-11T20:00:00Z', 'updatedAt' => '2026-09-11T20:00:00Z', 'author' => ['login' => 'testuser']]]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($comment->refresh())->body->toBe('Updated text');
    expect($queueItem->refresh())->status->toBe('pushed');
});
