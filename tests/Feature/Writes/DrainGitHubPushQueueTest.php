<?php

declare(strict_types=1);

use App\Actions\ApplyProjectItemFields;
use App\Actions\CloseTodoIssue;
use App\Actions\DeleteLabel;
use App\Actions\DrainGitHubPushQueue;
use App\Actions\RenameLabel;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubSyncException;
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
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'archive_issue']);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    expect($item->refresh())->status->toBe('needs_attention')->last_error->toContain('archive_issue');
    Http::assertNothingSent();
});

it('gives up creating an issue on GitHub when the target issue no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => 999999]);
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

it('renames a Group option on GitHub by id, leaving other options untouched', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'data_type' => 'SINGLE_SELECT', 'github_node_id' => 'F_group']);
    $other = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_other', 'name' => 'Other', 'position' => 0]);
    $renamed = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_renamed', 'name' => 'Renamed', 'position' => 1]);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'rename_group_option', 'target_type' => 'project_field_option', 'target_id' => $renamed->id, 'payload' => ['name' => 'Renamed']]);
    Http::fake([
        '*' => Http::sequence()
            ->push(['data' => ['node' => ['id' => 'F_group', 'options' => [
                ['id' => 'O_other', 'name' => 'Other', 'color' => 'BLUE', 'description' => ''],
                ['id' => 'O_renamed', 'name' => 'Old name', 'color' => 'GRAY', 'description' => ''],
            ]]]])
            ->push(['data' => ['updateProjectV2Field' => ['projectV2Field' => ['id' => 'F_group', 'options' => [
                ['id' => 'O_other', 'name' => 'Other', 'color' => 'BLUE', 'description' => ''],
                ['id' => 'O_renamed', 'name' => 'Renamed', 'color' => 'GRAY', 'description' => ''],
            ]]]]]),
    ]);

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($renamed->refresh()->name)->toBe('Renamed');
    expect($other->refresh()->name)->toBe('Other');
    expect($item->refresh()->status)->toBe('pushed');
});

it('waits to rename a Group option that has not been created on GitHub yet', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $pending = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => null]);
    GitHubPushQueueItem::factory()->create(['operation' => 'rename_group_option', 'target_type' => 'project_field_option', 'target_id' => $pending->id, 'payload' => ['name' => 'New name']]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('deletes a Group option from GitHub using the identifiers captured in the payload, since the local row is already gone', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'delete_group_option', 'target_type' => 'project_field_option', 'target_id' => 999, 'payload' => ['github_option_id' => 'O_gone', 'field_github_node_id' => 'F_group']]);
    Http::fake([
        '*' => Http::sequence()
            ->push(['data' => ['node' => ['id' => 'F_group', 'options' => [
                ['id' => 'O_gone', 'name' => 'Gone', 'color' => 'GRAY', 'description' => ''],
                ['id' => 'O_keep', 'name' => 'Keep', 'color' => 'BLUE', 'description' => ''],
            ]]]])
            ->push(['data' => ['updateProjectV2Field' => ['projectV2Field' => ['id' => 'F_group', 'options' => [
                ['id' => 'O_keep', 'name' => 'Keep', 'color' => 'BLUE', 'description' => ''],
            ]]]]]),
    ]);

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh()->status)->toBe('pushed');
});

it('gives up deleting a Group option remotely when its GitHub identifiers are missing from the payload', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'delete_group_option', 'target_type' => 'project_field_option', 'target_id' => 999, 'payload' => []]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    expect($item->refresh()->status)->toBe('needs_attention');
    Http::assertNothingSent();
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

it('renames a label on GitHub by its node id', function (): void {
    $label = Label::factory()->create(['github_node_id' => 'L_1', 'name' => 'new name']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'rename_label', 'target_type' => 'label', 'target_id' => $label->id, 'payload' => ['name' => 'new name']]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['updateLabel' => ['label' => ['id' => 'L_1', 'name' => 'new name', 'color' => '008672', 'description' => null]]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh()->status)->toBe('pushed');
});

it('waits to rename a label that has not been created on GitHub yet', function (): void {
    $label = Label::factory()->create(['github_node_id' => null]);
    GitHubPushQueueItem::factory()->create(['operation' => 'rename_label', 'target_type' => 'label', 'target_id' => $label->id, 'payload' => ['name' => 'new name']]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('deletes a label on GitHub by its node id', function (): void {
    $label = Label::factory()->create(['github_node_id' => 'L_1']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'delete_label', 'target_type' => 'label', 'target_id' => $label->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['deleteLabel' => ['clientMutationId' => null]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh()->status)->toBe('pushed');
});

it('gives up deleting a label remotely when it was never pushed to GitHub in the first place', function (): void {
    $label = Label::factory()->create(['github_node_id' => null]);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'delete_label', 'target_type' => 'label', 'target_id' => $label->id]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    expect($item->refresh()->status)->toBe('needs_attention');
    Http::assertNothingSent();
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

it('sends the local close reason to GitHub as the stateReason variable', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test', 'state' => 'OPEN']);
    GitHubPushQueueItem::factory()->create(['operation' => 'close_issue', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['stateReason' => 'NOT_PLANNED']]);
    $sent = null;
    Http::fake(function (Request $request) use (&$sent) {
        $sent = (array) ($request->data()['variables'] ?? null);

        return Http::response(['data' => ['closeIssue' => ['issue' => ['id' => 'I_test', 'state' => 'CLOSED', 'stateReason' => 'NOT_PLANNED', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200);
    });

    app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($sent)->toBe(['issueId' => 'I_test', 'stateReason' => 'NOT_PLANNED']);
});

it('sends a null stateReason when the close carries none', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test', 'state' => 'OPEN']);
    GitHubPushQueueItem::factory()->create(['operation' => 'close_issue', 'target_type' => 'issue', 'target_id' => $issue->id]);
    $sent = null;
    Http::fake(function (Request $request) use (&$sent) {
        $sent = (array) ($request->data()['variables'] ?? null);

        return Http::response(['data' => ['closeIssue' => ['issue' => ['id' => 'I_test', 'state' => 'CLOSED', 'stateReason' => null, 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200);
    });

    app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($sent)->toBe(['issueId' => 'I_test', 'stateReason' => null]);
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

it('gives up creating a Group option when the target option no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_group_option', 'target_type' => 'project_field_option', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers creating a Group option when GitHub is unreachable', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => null]);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_group_option', 'target_type' => 'project_field_option', 'target_id' => $option->id, 'payload' => ['name' => 'New Group', 'color' => 'GRAY'], 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('defers creating a Group option when GitHub does not return the newly created option', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => null, 'name' => 'New Group']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_group_option', 'target_type' => 'project_field_option', 'target_id' => $option->id, 'payload' => ['name' => 'New Group', 'color' => 'GRAY'], 'attempts' => 0]);
    Http::fake([
        '*' => Http::sequence()
            ->push(['data' => ['node' => ['id' => 'F_group', 'options' => []]]])
            ->push(['data' => ['updateProjectV2Field' => ['projectV2Field' => ['id' => 'F_group', 'options' => [
                ['id' => 'O_other', 'name' => 'Something else', 'color' => 'BLUE', 'description' => ''],
            ]]]]]),
    ]);

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up renaming a Group option when the target option no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'rename_group_option', 'target_type' => 'project_field_option', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers renaming a Group option that no longer exists on GitHub', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_missing']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'rename_group_option', 'target_type' => 'project_field_option', 'target_id' => $option->id, 'payload' => ['name' => 'New name'], 'attempts' => 0]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['node' => ['id' => 'F_group', 'options' => [
        ['id' => 'O_other', 'name' => 'Other', 'color' => 'BLUE', 'description' => ''],
    ]]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('defers deleting a Group option when GitHub is unreachable', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'delete_group_option', 'target_type' => 'project_field_option', 'target_id' => 999, 'payload' => ['github_option_id' => 'O_gone', 'field_github_node_id' => 'F_group'], 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up creating a label on GitHub when the target label no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_label', 'target_type' => 'label', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('gives up renaming a label on GitHub when the target label no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'rename_label', 'target_type' => 'label', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers renaming a label when GitHub is unreachable', function (): void {
    $label = Label::factory()->create(['github_node_id' => 'L_1', 'name' => 'new name']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'rename_label', 'target_type' => 'label', 'target_id' => $label->id, 'payload' => ['name' => 'new name'], 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up deleting a label on GitHub when the target label no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'delete_label', 'target_type' => 'label', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers deleting a label when GitHub is unreachable', function (): void {
    $label = Label::factory()->create(['github_node_id' => 'L_1']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'delete_label', 'target_type' => 'label', 'target_id' => $label->id, 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up adding a project membership when the target membership no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'add_project_membership', 'target_type' => 'project_item', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers adding a project membership when GitHub is unreachable', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => null]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'add_project_membership', 'target_type' => 'project_item', 'target_id' => $item->id, 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up setting a project item group when the target membership no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'set_project_item_group', 'target_type' => 'project_item', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('waits to set a project item group until the membership is pushed', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_group']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => null, 'group_option_id' => $option->id]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_project_item_group', 'target_type' => 'project_item', 'target_id' => $item->id, 'payload' => ['group_option_id' => $option->id], 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('gives up setting a project item group when the selected option no longer exists locally', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_project_item_group', 'target_type' => 'project_item', 'target_id' => $item->id, 'payload' => ['group_option_id' => 999999]]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('waits to set a project item group until the option is pushed', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => null]);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_project_item_group', 'target_type' => 'project_item', 'target_id' => $item->id, 'payload' => ['group_option_id' => $option->id], 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('gives up setting a project item group when no option is specified', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_project_item_group', 'target_type' => 'project_item', 'target_id' => $item->id, 'payload' => ['group_option_id' => null]]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers setting a project item group when GitHub is unreachable', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_group']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'group_option_id' => $option->id]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_project_item_group', 'target_type' => 'project_item', 'target_id' => $item->id, 'payload' => ['group_option_id' => $option->id], 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('sets a project item priority on GitHub after the item and option are pushed', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority', 'github_node_id' => 'F_priority']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_priority']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'priority_option_id' => $option->id]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_project_item_priority', 'target_type' => 'project_item', 'target_id' => $item->id, 'payload' => ['priority_option_id' => $option->id]]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['updateProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PI_test', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->priority_option_id->toBe($option->id);
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('gives up adding issue labels when the target issue no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'add_issue_labels', 'target_type' => 'issue', 'target_id' => 999999, 'payload' => ['label_ids' => []]]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('gives up adding issue labels when one or more labels no longer exist locally', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'add_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['label_ids' => [999999]]]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers adding issue labels when GitHub is unreachable', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $label = Label::factory()->for($repository, 'repository')->create(['github_node_id' => 'L_test1']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'add_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['label_ids' => [$label->id]], 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up setting an issue parent when the target issue no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_parent', 'target_type' => 'issue', 'target_id' => 999999, 'payload' => ['parent_issue_id' => null]]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('gives up setting an issue parent when the specified parent no longer exists locally', function (): void {
    $child = Issue::factory()->create(['github_node_id' => 'I_child']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_parent', 'target_type' => 'issue', 'target_id' => $child->id, 'payload' => ['parent_issue_id' => 999999]]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('waits to set an issue parent until the parent is pushed', function (): void {
    $repository = GitHubRepository::factory()->create();
    $parent = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null]);
    $child = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_child']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_parent', 'target_type' => 'issue', 'target_id' => $child->id, 'payload' => ['parent_issue_id' => $parent->id], 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('gives up setting an issue parent when no parent is specified', function (): void {
    $child = Issue::factory()->create(['github_node_id' => 'I_child']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_parent', 'target_type' => 'issue', 'target_id' => $child->id, 'payload' => ['parent_issue_id' => null]]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers setting an issue parent when GitHub is unreachable', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $parent = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_parent']);
    $child = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_child']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_parent', 'target_type' => 'issue', 'target_id' => $child->id, 'payload' => ['parent_issue_id' => $parent->id], 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up updating an issue body when the target issue no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'update_issue_body', 'target_type' => 'issue', 'target_id' => 999999, 'payload' => ['title' => 'x', 'body' => null]]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers updating an issue body when GitHub is unreachable', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'update_issue_body', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['title' => 'New', 'body' => null], 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up closing an issue when the target issue no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'close_issue', 'target_type' => 'issue', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('waits to close an issue until it is pushed', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => null, 'state' => 'OPEN']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'close_issue', 'target_type' => 'issue', 'target_id' => $issue->id, 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('still calls GitHub to close an issue that a real CloseTodoIssue write already marked CLOSED locally', function (): void {
    // Regression test for a real bug (2026-09-22): CloseTodoIssue always sets local state to CLOSED
    // *before* enqueueing the push, so by the time drain runs, local state is always already CLOSED --
    // a short-circuit keyed on that fact meant the closeIssue mutation was never actually reachable for
    // any real close. Driving this through the real Action (not a bare factory row) is what exposes it.
    $issue = Issue::factory()->create(['github_node_id' => 'I_test', 'state' => 'OPEN']);
    app(CloseTodoIssue::class)->handle($issue);
    $called = false;
    Http::fake(function (Request $request) use (&$called) {
        $called = true;

        return Http::response(['data' => ['closeIssue' => ['issue' => ['id' => 'I_test', 'state' => 'CLOSED', 'stateReason' => null, 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200);
    });

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($called)->toBeTrue();
    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
});

it('defers closing an issue when GitHub is unreachable', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_test', 'state' => 'OPEN']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'close_issue', 'target_type' => 'issue', 'target_id' => $issue->id, 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up deleting an issue on GitHub when the target issue no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'delete_issue', 'target_type' => 'issue', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers deleting an issue when GitHub is unreachable', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test', 'is_available' => false]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'delete_issue', 'target_type' => 'issue', 'target_id' => $issue->id, 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('reconciles a delete-project-item row when the membership record is already gone locally', function (): void {
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'delete_project_item', 'target_type' => 'project_item', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh()->status)->toBe('pushed');
    Http::assertNothingSent();
});

it('reconciles a delete-project-item row when the membership is already marked unavailable', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'is_available' => false]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'delete_project_item', 'target_type' => 'project_item', 'target_id' => $item->id]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh()->status)->toBe('pushed');
    Http::assertNothingSent();
});

it('gives up deleting a project membership that has no remote identity to delete', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => null, 'is_available' => true]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'delete_project_item', 'target_type' => 'project_item', 'target_id' => $item->id]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers deleting a project membership when GitHub is unreachable', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'is_available' => true]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'delete_project_item', 'target_type' => 'project_item', 'target_id' => $item->id, 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up clearing a project item group when the target membership no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'clear_project_item_group', 'target_type' => 'project_item', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('still calls GitHub to clear a project item group that a real ApplyProjectItemFields write already nulled locally', function (): void {
    // Regression test for a real bug (2026-09-22): ApplyProjectItemFields always nulls group_option_id
    // locally *before* enqueueing clear_project_item_group, so a short-circuit keyed on that column
    // being null meant the clearProjectV2ItemFieldValue mutation was never actually reachable.
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_group']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'group_option_id' => $option->id]);
    app(ApplyProjectItemFields::class)->handle($item, null, null);
    $called = false;
    Http::fake(function (Request $request) use (&$called) {
        $called = true;

        return Http::response(['data' => ['clearProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PI_test', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200);
    });

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($called)->toBeTrue();
    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh()->group_option_id)->toBeNull();
});

it('still calls GitHub to clear a project item priority that a real ApplyProjectItemFields write already nulled locally', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority', 'github_node_id' => 'F_priority']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_priority']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'priority_option_id' => $option->id]);
    app(ApplyProjectItemFields::class)->handle($item, null, null);
    $called = false;
    Http::fake(function (Request $request) use (&$called) {
        $called = true;

        return Http::response(['data' => ['clearProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PI_test', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200);
    });

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($called)->toBeTrue();
    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh()->priority_option_id)->toBeNull();
});

it('gives up clearing a project item group when the field is no longer available locally, without calling GitHub', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'group_option_id' => null]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'clear_project_item_group', 'target_type' => 'project_item', 'target_id' => $item->id]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    expect($queueItem->refresh())->status->toBe('needs_attention')->last_error->toContain('field is not available');
    Http::assertNothingSent();
});

it('confirms pushRenameLabel already calls GitHub for a real RenameLabel write (not affected by the same bug class)', function (): void {
    $renamed = Label::factory()->create(['github_node_id' => 'L_rename', 'name' => 'old-name']);
    app(RenameLabel::class)->handle($renamed, 'new name');
    $called = false;
    Http::fake(function (Request $request) use (&$called) {
        $called = true;

        return Http::response(['data' => ['updateProjectV2Field' => ['projectV2Field' => ['options' => []]]]], 200);
    });

    app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($called)->toBeTrue();
});

it('confirms pushDeleteLabel already calls GitHub for a real DeleteLabel write (not affected by the same bug class)', function (): void {
    $deleted = Label::factory()->create(['github_node_id' => 'L_delete']);
    app(DeleteLabel::class)->handle($deleted);
    $called = false;
    Http::fake(function (Request $request) use (&$called) {
        $called = true;

        return Http::response(['data' => ['deleteLabel' => ['clientMutationId' => null]]], 200);
    });

    app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($called)->toBeTrue();
});

it('waits to clear a project item group until the membership is pushed', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_group']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => null, 'group_option_id' => $option->id]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'clear_project_item_group', 'target_type' => 'project_item', 'target_id' => $item->id, 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('defers clearing a project item group when GitHub is unreachable', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_group']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'group_option_id' => $option->id]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'clear_project_item_group', 'target_type' => 'project_item', 'target_id' => $item->id, 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('clears a project item priority on GitHub', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority', 'github_node_id' => 'F_priority']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_priority']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_test', 'priority_option_id' => $option->id]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'clear_project_item_priority', 'target_type' => 'project_item', 'target_id' => $item->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['clearProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PI_test', 'updatedAt' => '2026-09-11T20:00:00Z']]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->priority_option_id->toBeNull();
    expect($queueItem->refresh())->status->toBe('pushed');
});

it('gives up setting issue labels when the target issue no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_labels', 'target_type' => 'issue', 'target_id' => 999999, 'payload' => ['add_label_ids' => [], 'remove_label_ids' => []]]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('waits to set issue labels until the issue is pushed', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => null]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['add_label_ids' => [], 'remove_label_ids' => []], 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('gives up setting issue labels when one or more labels to add no longer exist locally', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['add_label_ids' => [999999], 'remove_label_ids' => []]]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('waits to set issue labels until all labels to add are pushed', function (): void {
    $repository = GitHubRepository::factory()->create();
    $label = Label::factory()->for($repository, 'repository')->create(['github_node_id' => null]);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['add_label_ids' => [$label->id], 'remove_label_ids' => []], 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('reconciles a set-issue-labels row that ends up with nothing to add or remove', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['add_label_ids' => [], 'remove_label_ids' => []]]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh()->status)->toBe('pushed');
    Http::assertNothingSent();
});

it('defers setting issue labels when adding labels fails on GitHub', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $label = Label::factory()->for($repository, 'repository')->create(['github_node_id' => 'L_test1']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['add_label_ids' => [$label->id], 'remove_label_ids' => []], 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('defers setting issue labels when removing labels fails on GitHub', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $label = Label::factory()->for($repository, 'repository')->create(['github_node_id' => 'L_test1']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['add_label_ids' => [], 'remove_label_ids' => [$label->id]], 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up removing an issue parent when the target issue no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'remove_issue_parent', 'target_type' => 'issue', 'target_id' => 999999, 'payload' => ['parent_github_node_id' => 'I_parent']]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('gives up removing an issue parent when the issue has no remote identity yet', function (): void {
    $child = Issue::factory()->create(['github_node_id' => null]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'remove_issue_parent', 'target_type' => 'issue', 'target_id' => $child->id, 'payload' => ['parent_github_node_id' => 'I_parent']]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('gives up removing an issue parent when no parent node id was captured in the payload', function (): void {
    $child = Issue::factory()->create(['github_node_id' => 'I_child']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'remove_issue_parent', 'target_type' => 'issue', 'target_id' => $child->id, 'payload' => ['parent_github_node_id' => '']]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('defers removing an issue parent when GitHub is unreachable', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $child = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_child']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'remove_issue_parent', 'target_type' => 'issue', 'target_id' => $child->id, 'payload' => ['parent_github_node_id' => 'I_parent'], 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up creating a comment when the target comment no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_comment', 'target_type' => 'comment', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('reconciles a create-comment row already pushed by a previous run', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $comment = Comment::factory()->create(['issue_id' => $issue->id, 'github_node_id' => 'C_already']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'create_comment', 'target_type' => 'comment', 'target_id' => $comment->id]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh()->status)->toBe('pushed');
    Http::assertNothingSent();
});

it('defers creating a comment when GitHub is unreachable', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $comment = Comment::factory()->create(['issue_id' => $issue->id, 'github_node_id' => null, 'body' => 'New comment']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'create_comment', 'target_type' => 'comment', 'target_id' => $comment->id, 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('gives up updating a comment when the target comment no longer exists locally', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'update_comment', 'target_type' => 'comment', 'target_id' => 999999]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 1, 'waiting' => 0]);
    Http::assertNothingSent();
});

it('waits to update a comment until it is pushed', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $comment = Comment::factory()->create(['issue_id' => $issue->id, 'github_node_id' => null]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'update_comment', 'target_type' => 'comment', 'target_id' => $comment->id, 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('defers updating a comment when GitHub is unreachable', function (): void {
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $comment = Comment::factory()->create(['issue_id' => $issue->id, 'github_node_id' => 'C_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'update_comment', 'target_type' => 'comment', 'target_id' => $comment->id, 'attempts' => 0]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'rate limited']]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('reconciles a create-group-option row already pushed by a previous run', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_already']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_group_option', 'target_type' => 'project_field_option', 'target_id' => $option->id]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh()->status)->toBe('pushed');
    Http::assertNothingSent();
});

it('defers creating a Group option when GitHub returns a malformed field lookup', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => null]);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_group_option', 'target_type' => 'project_field_option', 'target_id' => $option->id, 'payload' => ['name' => 'New Group', 'color' => 'GRAY'], 'attempts' => 0]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['node' => null]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('defers creating a Group option when GitHub returns an invalid existing option', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => null]);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_group_option', 'target_type' => 'project_field_option', 'target_id' => $option->id, 'payload' => ['name' => 'New Group', 'color' => 'GRAY'], 'attempts' => 0]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['node' => ['id' => 'F_group', 'options' => [
        ['id' => 'O_existing', 'name' => 'Existing'],
    ]]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('throws when GitHub returns a malformed option while finalizing a created Group option', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => null, 'name' => 'New Group']);
    GitHubPushQueueItem::factory()->create(['operation' => 'create_group_option', 'target_type' => 'project_field_option', 'target_id' => $option->id, 'payload' => ['name' => 'New Group', 'color' => 'GRAY']]);
    Http::fake([
        '*' => Http::sequence()
            ->push(['data' => ['node' => ['id' => 'F_group', 'options' => []]]])
            ->push(['data' => ['updateProjectV2Field' => ['projectV2Field' => ['id' => 'F_group', 'options' => [
                ['id' => 'O_new', 'name' => 'New Group', 'color' => 'GRAY', 'description' => ''],
                ['id' => 'O_bad'],
            ]]]]]),
    ]);

    expect(fn () => app(DrainGitHubPushQueue::class)->handle('test-token'))
        ->toThrow(GitHubSyncException::class, 'invalid Group option');
});

it('defers renaming a Group option when GitHub returns a malformed field lookup', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_renamed']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'rename_group_option', 'target_type' => 'project_field_option', 'target_id' => $option->id, 'payload' => ['name' => 'New name'], 'attempts' => 0]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['node' => null]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('defers renaming a Group option when GitHub returns an invalid existing option', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_renamed']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'rename_group_option', 'target_type' => 'project_field_option', 'target_id' => $option->id, 'payload' => ['name' => 'New name'], 'attempts' => 0]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['node' => ['id' => 'F_group', 'options' => [
        ['id' => 'O_renamed', 'name' => 'Old name'],
    ]]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('renames a Group option while skipping a malformed entry in the returned option list', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'F_group']);
    $renamed = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_renamed', 'name' => 'Renamed']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'rename_group_option', 'target_type' => 'project_field_option', 'target_id' => $renamed->id, 'payload' => ['name' => 'Renamed']]);
    Http::fake([
        '*' => Http::sequence()
            ->push(['data' => ['node' => ['id' => 'F_group', 'options' => [
                ['id' => 'O_renamed', 'name' => 'Old name', 'color' => 'GRAY', 'description' => ''],
            ]]]])
            ->push(['data' => ['updateProjectV2Field' => ['projectV2Field' => ['id' => 'F_group', 'options' => [
                ['id' => 'O_renamed', 'name' => 'Renamed', 'color' => 'GRAY', 'description' => ''],
                ['id' => 'O_bad'],
            ]]]]]),
    ]);

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($renamed->refresh()->name)->toBe('Renamed');
    expect(ProjectFieldOption::query()->count())->toBe(1);
});

it('defers deleting a Group option when GitHub returns a malformed field lookup', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'delete_group_option', 'target_type' => 'project_field_option', 'target_id' => 999, 'payload' => ['github_option_id' => 'O_gone', 'field_github_node_id' => 'F_group'], 'attempts' => 0]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['node' => null]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('defers deleting a Group option when GitHub returns an invalid existing option', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'delete_group_option', 'target_type' => 'project_field_option', 'target_id' => 999, 'payload' => ['github_option_id' => 'O_gone', 'field_github_node_id' => 'F_group'], 'attempts' => 0]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['node' => ['id' => 'F_group', 'options' => [
        ['id' => 'O_gone', 'name' => 'Gone'],
    ]]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh())->attempts->toBe(1)->status->toBe('pending');
});

it('reconciles a create-label row already pushed by a previous run', function (): void {
    $label = Label::factory()->create(['github_node_id' => 'L_already']);
    $item = GitHubPushQueueItem::factory()->create(['operation' => 'create_label', 'target_type' => 'label', 'target_id' => $label->id]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($item->refresh()->status)->toBe('pushed');
    Http::assertNothingSent();
});

it('reconciles an add-project-membership row already pushed by a previous run', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'P_test']);
    $issue = Issue::factory()->create(['github_node_id' => 'I_test']);
    $item = ProjectItem::factory()->create(['project_id' => $project->id, 'issue_id' => $issue->id, 'github_node_id' => 'PI_already']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'add_project_membership', 'target_type' => 'project_item', 'target_id' => $item->id]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 1, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh()->status)->toBe('pushed');
    Http::assertNothingSent();
});

it('waits to add issue labels until the issue itself is pushed', function (): void {
    $repository = GitHubRepository::factory()->create();
    $label = Label::factory()->for($repository, 'repository')->create(['github_node_id' => 'L_test1']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => null]);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'add_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['label_ids' => [$label->id]], 'attempts' => 0]);
    Http::fake();

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 0, 'needs_attention' => 0, 'waiting' => 1]);
    Http::assertNothingSent();
});

it('defers setting issue labels when GitHub does not confirm the labels were added', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R_test']);
    $label = Label::factory()->for($repository, 'repository')->create(['github_node_id' => 'L_test1']);
    $issue = Issue::factory()->create(['repository_id' => $repository->id, 'github_node_id' => 'I_test']);
    $queueItem = GitHubPushQueueItem::factory()->create(['operation' => 'set_issue_labels', 'target_type' => 'issue', 'target_id' => $issue->id, 'payload' => ['add_label_ids' => [$label->id], 'remove_label_ids' => []], 'attempts' => 0]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['addLabelsToLabelable' => ['labelable' => null]]], 200));

    $result = app(DrainGitHubPushQueue::class)->handle('test-token');

    expect($result)->toBe(['pushed' => 0, 'deferred' => 1, 'needs_attention' => 0, 'waiting' => 0]);
    expect($queueItem->refresh())->attempts->toBe(1)->status->toBe('pending');
});
