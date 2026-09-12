<?php

declare(strict_types=1);

use App\Actions\AddIssueToGitHubProject;
use App\Actions\CloseGitHubIssue;
use App\Actions\CreateGitHubComment;
use App\Actions\CreateGitHubIssue;
use App\Actions\GetIssueDetails;
use App\Actions\SetIssueParent;
use App\Actions\SyncGitHub;
use App\Actions\UpdateGitHubComment;
use App\Actions\UpdateGitHubIssue;
use App\Actions\UpdateGitHubProject;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Models\User;
use App\Services\GitHub\GitHubSyncException;
use Livewire\Livewire;

it('browses an area and opens the selected issue without network access', function (): void {
    $user = User::factory()->create();
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $issue = Issue::factory()->create(['title' => 'A task to inspect', 'body' => 'Useful **notes**']);
    $label = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'next']);
    $issue->labels()->attach($label);
    ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();
    Livewire::actingAs($user)->test('pages::workspace')->call('chooseArea', $project->id)->assertSet('captureArea', $project->id)
        ->assertSee('A task to inspect')->assertSeeHtml('data-label="next"')->assertSeeHtml('data-project-color="#'.$project->color.'"')->set('selected', $issue->id)
        ->assertSee('Useful')->assertSee('Open in GitHub')->assertSee('Discussion & history')
        ->set('selected', 0)->assertDontSee('Discussion & history');
});

it('keeps a matched child visible with its parent during search', function (): void {
    $root = Issue::factory()->create(['title' => 'Website context']);
    Issue::factory()->for($root, 'parent')->create(['title' => 'Specific needle']);
    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('search', 'Specific needle')->assertSee('Website context')->assertSee('Specific needle')->assertSee('Parent context');
});

it('strips executable HTML and unsafe links from descriptions and comments', function (): void {
    $issue = Issue::factory()->create(['body' => '<script>alert(1)</script><img src=x onerror=alert(1)> [bad](javascript:alert%281%29) **Safe notes**']);
    Comment::factory()->for($issue, 'issue')->create(['body' => '<iframe src="https://example.test"></iframe> [bad](javascript:alert%281%29)']);
    $detail = app(GetIssueDetails::class)->handle($issue->id);
    expect($detail['body'])->toContain('<strong>Safe notes</strong>')->not->toContain('<script', '<img', 'javascript:')
        ->and($detail['comments'][0]['body'])->not->toContain('<iframe', 'javascript:');
});

it('returns not found for an unavailable issue instead of leaking stale details', function (): void {
    $issue = Issue::factory()->create(['is_available' => false]);
    $this->actingAs(User::factory()->create())->get('/?issue='.$issue->id)->assertNotFound();
});

it('refreshes the workspace from GitHub through the existing sync action', function (): void {
    config(['github.token' => 'test-token']);
    $sync = Mockery::mock(SyncGitHub::class);
    $sync->shouldReceive('handle')->once()->with('test-token', true);
    app()->instance(SyncGitHub::class, $sync);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('refreshFromGitHub')
        ->assertSet('refreshMessage', 'Updated from GitHub just now.')
        ->assertSet('refreshError', null)
        ->assertSee('Updated from GitHub just now.');
});

it('explains how to enable in-app refresh without a server-side credential', function (): void {
    config(['github.token' => null]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('refreshFromGitHub')
        ->assertSet('refreshError', 'In-app refresh needs a server-side GitHub token. Set GITHUB_TOKEN and try again.')
        ->assertSet('refreshMessage', null)
        ->assertSee('In-app refresh needs a server-side GitHub token. Set GITHUB_TOKEN and try again.');
});

it('keeps the cached workspace visible when a refresh fails', function (): void {
    config(['github.token' => 'test-token']);
    $sync = Mockery::mock(SyncGitHub::class);
    $sync->shouldReceive('handle')->once()->andThrow(new GitHubSyncException('GitHub HTTP 429; check access or retry later.'));
    app()->instance(SyncGitHub::class, $sync);
    Issue::factory()->create(['title' => 'Still here']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('refreshFromGitHub')
        ->assertSet('refreshError', 'GitHub HTTP 429; check access or retry later.')
        ->assertSee('Still here')
        ->assertSee('GitHub HTTP 429; check access or retry later.');
});

it('quickly captures a task through GitHub and selects its local projection', function (): void {
    config(['github.token' => 'test-token']);
    $issue = Issue::factory()->create(['title' => 'Capture this']);
    $create = Mockery::mock(CreateGitHubIssue::class);
    $create->shouldReceive('handle')->once()->with('test-token', 'Capture this')->andReturn($issue);
    app()->instance(CreateGitHubIssue::class, $create);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('newTitle', '')
        ->assertSet('captureError', null)
        ->assertSet('selected', $issue->id);
});

it('keeps a quick-capture draft and explains GitHub write errors', function (): void {
    config(['github.token' => 'test-token']);
    $create = Mockery::mock(CreateGitHubIssue::class);
    $create->shouldReceive('handle')->once()->with('test-token', 'Capture this')->andThrow(new GitHubSyncException('GitHub is temporarily unavailable.'));
    app()->instance(CreateGitHubIssue::class, $create);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('newTitle', 'Capture this')
        ->assertSet('captureError', 'GitHub is temporarily unavailable.')
        ->assertSee('GitHub is temporarily unavailable.');
});

it('requires a title before quick capture', function (): void {
    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('capture')
        ->assertHasErrors(['newTitle' => 'required']);
});

it('places a quick-captured task in its selected area', function (): void {
    config(['github.token' => 'test-token']);
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $issue = Issue::factory()->create(['title' => 'Capture this']);
    $create = Mockery::mock(CreateGitHubIssue::class);
    $create->shouldReceive('handle')->once()->with('test-token', 'Capture this')->andReturn($issue);
    $add = Mockery::mock(AddIssueToGitHubProject::class);
    $add->shouldReceive('handle')->once()->with('test-token', $issue, Mockery::on(fn (GitHubProject $area): bool => $area->is($project)));
    app()->instance(CreateGitHubIssue::class, $create);
    app()->instance(AddIssueToGitHubProject::class, $add);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('captureArea', $project->id)
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('captureError', null)
        ->assertSet('selected', $issue->id);
});

it('keeps no duplicate-prone quick-capture draft after the issue succeeds but area placement fails', function (): void {
    config(['github.token' => 'test-token']);
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $issue = Issue::factory()->create(['title' => 'Capture this']);
    $create = Mockery::mock(CreateGitHubIssue::class);
    $create->shouldReceive('handle')->once()->with('test-token', 'Capture this')->andReturn($issue);
    $add = Mockery::mock(AddIssueToGitHubProject::class);
    $add->shouldReceive('handle')->once()->with('test-token', $issue, Mockery::any())->andThrow(new GitHubSyncException('GitHub is temporarily unavailable.'));
    app()->instance(CreateGitHubIssue::class, $create);
    app()->instance(AddIssueToGitHubProject::class, $add);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('captureArea', $project->id)
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('newTitle', '')
        ->assertSet('selected', $issue->id)
        ->assertSet('captureError', 'Task was created, but could not be added to Personal Projects. GitHub is temporarily unavailable.');
});

it('retains a quick-capture draft when its selected area is no longer available', function (): void {
    config(['github.token' => 'test-token']);
    $create = Mockery::mock(CreateGitHubIssue::class);
    $create->shouldNotReceive('handle');
    app()->instance(CreateGitHubIssue::class, $create);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('captureArea', 999_999)
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('newTitle', 'Capture this')
        ->assertSet('captureError', 'The selected area is no longer available. Refresh and try again.');
});

it('uses a selected organizational parent as the default for quick capture', function (): void {
    config(['github.token' => 'test-token']);
    $parent = Issue::factory()->create(['title' => 'Career']);
    $label = Label::factory()->for($parent->repository, 'repository')->create(['name' => 'parent']);
    $parent->labels()->attach($label);
    $child = Issue::factory()->for($parent->repository, 'repository')->create(['title' => 'Capture this']);
    $create = Mockery::mock(CreateGitHubIssue::class);
    $create->shouldReceive('handle')->once()->with('test-token', 'Capture this')->andReturn($child);
    $setParent = Mockery::mock(SetIssueParent::class);
    $setParent->shouldReceive('handle')->once()->with('test-token', $child, Mockery::on(fn (Issue $candidate): bool => $candidate->is($parent)));
    app()->instance(CreateGitHubIssue::class, $create);
    app()->instance(SetIssueParent::class, $setParent);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('selected', $parent->id)
        ->assertSet('captureParent', $parent->id)
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('captureError', null)
        ->assertSet('selected', $child->id);
});

it('preserves the created issue without retrying its title when parent placement fails', function (): void {
    config(['github.token' => 'test-token']);
    $parent = Issue::factory()->create(['title' => 'Career']);
    $label = Label::factory()->for($parent->repository, 'repository')->create(['name' => 'parent']);
    $parent->labels()->attach($label);
    $child = Issue::factory()->for($parent->repository, 'repository')->create(['title' => 'Capture this']);
    $create = Mockery::mock(CreateGitHubIssue::class);
    $create->shouldReceive('handle')->once()->with('test-token', 'Capture this')->andReturn($child);
    $setParent = Mockery::mock(SetIssueParent::class);
    $setParent->shouldReceive('handle')->once()->with('test-token', $child, Mockery::any())->andThrow(new GitHubSyncException('GitHub is temporarily unavailable.'));
    app()->instance(CreateGitHubIssue::class, $create);
    app()->instance(SetIssueParent::class, $setParent);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('captureParent', $parent->id)
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('newTitle', '')
        ->assertSet('selected', $child->id)
        ->assertSet('captureError', 'Task was created, but could not be added under Career. GitHub is temporarily unavailable.');
});

it('shows Groups as area roots and hides the virtual root when filtering by that Group', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);
    $issue = Issue::factory()->create(['title' => 'Resume']);
    ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['group_option_id' => $group->id]);

    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)
        ->assertSeeHtml('data-group-root="'.$group->id.'"');

    $component->set('group', $group->id)->assertDontSeeHtml('data-group-root="'.$group->id.'"');
});

it('toggles compact label filters without hiding unlabelled selections by default', function (): void {
    $label = Label::factory()->create(['name' => 'next']);
    $matching = Issue::factory()->for($label->repository, 'repository')->create(['title' => 'Next task']);
    $matching->labels()->attach($label);
    $other = Issue::factory()->for($label->repository, 'repository')->create(['title' => 'Other task']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->assertSee('Next task')->assertSee('Other task')->assertSee('Labels')
        ->call('toggleLabel', 'next')->assertSet('labels', ['next'])
        ->assertSee('Next task')->assertDontSeeHtml('data-issue-number="'.$other->github_number.'"')
        ->call('toggleLabel', 'next')->assertSet('labels', [])
        ->assertSee('Other task');
});

it('opens task capture with the current Project and Group preselected', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)->set('group', $group->id)->call('openCapture')
        ->assertSet('captureOpen', true)->assertSet('captureArea', $project->id)->assertSet('captureGroup', $group->id)
        ->assertDontSee('New Group')->assertSee('Find parent')->assertSee('New label')
        ->set('captureGroup', -1)->assertSee('New Group');
});

it('sends a capture description to GitHub with the new task', function (): void {
    config(['github.token' => 'test-token']);
    $issue = Issue::factory()->create(['title' => 'Capture this', 'body' => 'Useful context']);
    $create = Mockery::mock(CreateGitHubIssue::class);
    $create->shouldReceive('handle')->once()->with('test-token', 'Capture this', 'Useful context')->andReturn($issue);
    app()->instance(CreateGitHubIssue::class, $create);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('newTitle', 'Capture this')->set('newBody', 'Useful context')->call('capture')
        ->assertSet('newTitle', '')->assertSet('newBody', '')->assertSet('selected', $issue->id);
});

it('edits a selected task title and description after GitHub confirms the update', function (): void {
    config(['github.token' => 'test-token']);
    $issue = Issue::factory()->create(['title' => 'Old title', 'body' => 'Old notes']);
    $update = Mockery::mock(UpdateGitHubIssue::class);
    $update->shouldReceive('handle')->once()->with('test-token', Mockery::on(fn (Issue $candidate): bool => $candidate->is($issue)), 'New title', 'New notes')
        ->andReturnUsing(function () use ($issue): Issue {
            $issue->update(['title' => 'New title', 'body' => 'New notes']);

            return $issue->refresh();
        });
    app()->instance(UpdateGitHubIssue::class, $update);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->assertSet('editingIssue', true)->assertSet('editTitle', 'Old title')->assertSet('editBody', 'Old notes')
        ->set('editTitle', 'New title')->set('editBody', 'New notes')->call('saveIssue')
        ->assertSet('editingIssue', false)->assertSet('editError', null)->assertSee('New title')->assertSee('New notes');
});

it('keeps an edit draft and explains a GitHub update failure', function (): void {
    config(['github.token' => 'test-token']);
    $issue = Issue::factory()->create(['title' => 'Old title']);
    $update = Mockery::mock(UpdateGitHubIssue::class);
    $update->shouldReceive('handle')->once()->andThrow(new GitHubSyncException('GitHub is temporarily unavailable.'));
    app()->instance(UpdateGitHubIssue::class, $update);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editTitle', 'New title')->set('editBody', 'New notes')->call('saveIssue')
        ->assertSet('editingIssue', true)->assertSet('editTitle', 'New title')->assertSet('editBody', 'New notes')
        ->assertSet('editError', 'GitHub is temporarily unavailable.');
});

it('saves a project name in GitHub and its display color locally', function (): void {
    config(['github.token' => 'test-token']);
    $project = GitHubProject::factory()->create(['title' => 'Old area', 'color' => '0f766e']);
    $update = Mockery::mock(UpdateGitHubProject::class);
    $update->shouldReceive('handle')->once()->with('test-token', Mockery::on(fn (GitHubProject $candidate): bool => $candidate->is($project)), 'New area')
        ->andReturnUsing(function () use ($project): GitHubProject {
            $project->update(['title' => 'New area']);

            return $project->refresh();
        });
    app()->instance(UpdateGitHubProject::class, $update);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)->call('openProjectSettings')
        ->assertSet('projectSettingsOpen', true)->assertSet('projectSettingsTitle', 'Old area')->assertSet('projectSettingsColor', '0f766e')
        ->set('projectSettingsTitle', 'New area')->set('projectSettingsColor', '7c3aed')->call('saveProjectSettings')
        ->assertSet('projectSettingsOpen', false)->assertSet('projectSettingsError', null)->assertSee('New area');

    expect($project->refresh()->color)->toBe('7c3aed');
});

it('keeps project settings open when GitHub rejects a renamed project', function (): void {
    config(['github.token' => 'test-token']);
    $project = GitHubProject::factory()->create(['title' => 'Old area']);
    $update = Mockery::mock(UpdateGitHubProject::class);
    $update->shouldReceive('handle')->once()->andThrow(new GitHubSyncException('GitHub is temporarily unavailable.'));
    app()->instance(UpdateGitHubProject::class, $update);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)->call('openProjectSettings')->set('projectSettingsTitle', 'New area')->call('saveProjectSettings')
        ->assertSet('projectSettingsOpen', true)->assertSet('projectSettingsError', 'GitHub is temporarily unavailable.');
});

it('switches the mobile workspace dropdown between Daily and an area', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseMobileNavigation', 'daily')->assertSet('view', 'daily')->assertSet('area', 0)
        ->call('chooseMobileNavigation', (string) $project->id)->assertSet('view', 'tasks')->assertSet('area', $project->id);
});

it('closes a selected task from its detail drawer after GitHub confirms it', function (): void {
    config(['github.token' => 'test-token']);
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $close = Mockery::mock(CloseGitHubIssue::class);
    $close->shouldReceive('handle')->once()->with('test-token', Mockery::on(fn (Issue $candidate): bool => $candidate->is($issue)))
        ->andReturnUsing(function () use ($issue): Issue {
            $issue->update(['state' => 'CLOSED']);

            return $issue->refresh();
        });
    app()->instance(CloseGitHubIssue::class, $close);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('Mark done')->call('closeIssue')->assertDontSee('Mark done');
});

it('adds and edits comments through the selected task drawer', function (): void {
    config(['github.token' => 'test-token']);
    $issue = Issue::factory()->create();
    $comment = Comment::factory()->for($issue, 'issue')->create(['body' => 'Old comment']);
    $create = Mockery::mock(CreateGitHubComment::class);
    $create->shouldReceive('handle')->once()->with('test-token', Mockery::on(fn (Issue $candidate): bool => $candidate->is($issue)), 'New comment')
        ->andReturn(Comment::factory()->for($issue, 'issue')->create(['body' => 'New comment']));
    $update = Mockery::mock(UpdateGitHubComment::class);
    $update->shouldReceive('handle')->once()->with('test-token', Mockery::on(fn (Comment $candidate): bool => $candidate->is($comment)), 'Edited comment')
        ->andReturnUsing(function () use ($comment): Comment {
            $comment->update(['body' => 'Edited comment']);

            return $comment->refresh();
        });
    app()->instance(CreateGitHubComment::class, $create);
    app()->instance(UpdateGitHubComment::class, $update);

    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->set('newCommentBody', 'New comment')->call('addComment')->assertSet('newCommentBody', '')->assertSee('New comment')
        ->call('beginEditComment', $comment->id)->assertSet('editingComment', $comment->id)->assertSet('editCommentBody', 'Old comment')
        ->set('editCommentBody', 'Edited comment')->call('saveComment')->assertSet('editingComment', 0)->assertSee('Edited comment');
});

it('loads the same contextual fields in task editing as task creation', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);
    $parent = Issue::factory()->create(['title' => 'Career root']);
    $label = Label::factory()->for($parent->repository, 'repository')->create(['name' => 'next']);
    $issue = Issue::factory()->for($parent, 'parent')->for($parent->repository, 'repository')->create(['title' => 'Resume']);
    $issue->labels()->attach($label);
    ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['group_option_id' => $group->id]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->assertSet('editingIssue', true)->assertSet('editArea', $project->id)
        ->assertSet('editGroup', $group->id)->assertSet('editParent', $parent->id)->assertSet('editLabels', [$label->id])
        ->assertDontSee('New Group')->assertSee('New label')
        ->set('editGroup', -1)->assertSee('New Group');
});
