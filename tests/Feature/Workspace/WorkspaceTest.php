<?php

declare(strict_types=1);

use App\Actions\CloseTodoIssue;
use App\Actions\GetIssueDetails;
use App\Actions\SyncGitHub;
use App\Actions\UpdateGitHubProject;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Models\User;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('browses an area and opens the selected issue without network access', function (): void {
    $user = User::factory()->create();
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $issue = Issue::factory()->create(['title' => 'A task to inspect', 'body' => 'Useful **notes**']);
    $label = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'next']);
    $issue->labels()->attach($label);
    ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();
    Livewire::actingAs($user)->test('pages::workspace')->call('chooseArea', $project->id)->assertSet('captureArea', $project->id)
        ->assertDispatched('area-changed', area: $project->id)
        ->assertSee('A task to inspect')->assertSeeHtml('data-label="next"')->assertSeeHtml('data-project-color="#'.$project->color.'"')->set('selected', $issue->id)
        ->assertSee('Useful')->assertSee('Open in GitHub')->assertSee('Discussion & history')
        ->set('selected', 0)->assertDontSee('Discussion & history');
});

it('applies the project filter when clicking a task row\'s project pill', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $issue = Issue::factory()->create(['title' => 'Pick me']);
    ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();

    // The badge only appears on genuinely top-level rows -- in the default "Project" sort, every root
    // task is already wrapped in its own project section header (depth > 0), so the badge would be
    // redundant there. It's still reachable in "Group" sort and the flat sorts, where no such
    // section-per-project wrapping happens.
    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('sortBy', 'group')
        ->assertSeeHtml('wire:click.stop="chooseArea('.$project->id.')"')
        ->call('chooseArea', $project->id)
        ->assertSet('area', $project->id);
});

it('always shows a task row\'s Group pill and applies both area and group filters when clicking it', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $groupOption = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);
    $issue = Issue::factory()->create(['title' => 'Pick me']);
    ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['group_option_id' => $groupOption->id]);

    // Filtering by the group already keeps the root unwrapped (no virtual Group header competes for
    // its own group badge), unlike the default view where a grouped root sits under one.
    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('group', $groupOption->id)
        ->assertSeeHtml('wire:click.stop="chooseGroup('.$project->id.', '.$groupOption->id.')"')
        ->call('chooseGroup', $project->id, $groupOption->id)
        ->assertSet('area', $project->id)->assertSet('group', $groupOption->id);
});

it('applies the label filter when clicking a task row\'s label pill', function (): void {
    $issue = Issue::factory()->create(['title' => 'Pick me']);
    $label = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'next']);
    $issue->labels()->attach($label);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->assertSeeHtml('wire:click.stop="toggleLabel(\'next\')"')
        ->call('toggleLabel', 'next')
        ->assertSet('labels', ['next']);
});

it('selects tasks in bulk mode by clicking the row, and clears the selection when bulk mode is toggled off', function (): void {
    $first = Issue::factory()->create(['title' => 'First']);
    $second = Issue::factory()->create(['title' => 'Second']);

    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->assertDontSeeHtml('toggleBulkSelect('.$first->id.')')
        ->call('toggleBulkMode')->assertSet('bulkMode', true)
        ->assertSeeHtml('toggleBulkSelect('.$first->id.')')
        ->call('toggleBulkSelect', $first->id)->assertSet('bulkSelected', [$first->id])
        ->call('toggleBulkSelect', $second->id)->assertSet('bulkSelected', [$first->id, $second->id])
        ->call('toggleBulkSelect', $first->id)->assertSet('bulkSelected', [$second->id]);

    $component->call('toggleBulkMode')->assertSet('bulkMode', false)->assertSet('bulkSelected', []);
});

it('bulk-moves selected tasks into a Group', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);
    $first = Issue::factory()->create();
    $second = Issue::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('toggleBulkMode')->call('toggleBulkSelect', $first->id)->call('toggleBulkSelect', $second->id)
        ->call('openBulkGroup')->assertSet('bulkGroupOpen', true)
        ->set('bulkGroupArea', $project->id)->set('bulkGroup', $group->id)->call('applyBulkGroup')
        ->assertSet('bulkGroupOpen', false);

    expect(ProjectItem::query()->where('issue_id', $first->id)->where('group_option_id', $group->id)->exists())->toBeTrue();
    expect(ProjectItem::query()->where('issue_id', $second->id)->where('group_option_id', $group->id)->exists())->toBeTrue();
});

it('bulk-sets a parent on selected tasks', function (): void {
    Http::fake();
    $parent = Issue::factory()->create(['title' => 'Parent task']);
    $first = Issue::factory()->create();
    $second = Issue::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('toggleBulkMode')->call('toggleBulkSelect', $first->id)->call('toggleBulkSelect', $second->id)
        ->call('openBulkParent')->assertSet('bulkParentOpen', true)
        ->set('bulkParent', $parent->id)->call('applyBulkParent')
        ->assertSet('bulkParentOpen', false);

    expect($first->refresh()->parent_issue_id)->toBe($parent->id);
    expect($second->refresh()->parent_issue_id)->toBe($parent->id);
});

it('bulk-adds a label to selected tasks', function (): void {
    Http::fake();
    $repository = GitHubRepository::factory()->create();
    $label = Label::factory()->for($repository, 'repository')->create(['name' => 'urgent']);
    $first = Issue::factory()->for($repository, 'repository')->create();
    $second = Issue::factory()->for($repository, 'repository')->create();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('toggleBulkMode')->call('toggleBulkSelect', $first->id)->call('toggleBulkSelect', $second->id)
        ->call('openBulkLabels')->assertSet('bulkLabelsOpen', true)
        ->call('toggleBulkLabel', $label->id)->call('applyBulkLabels')
        ->assertSet('bulkLabelsOpen', false);

    expect($first->labels()->pluck('labels.id')->all())->toBe([$label->id]);
    expect($second->labels()->pluck('labels.id')->all())->toBe([$label->id]);
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

it('restores every filter -- including the project pill and sort -- from the URL query string on load', function (): void {
    $project = GitHubProject::factory()->create();
    $otherProject = GitHubProject::factory()->create();
    $groupField = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $priorityField = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    $group = ProjectFieldOption::factory()->for($groupField, 'field')->create(['name' => 'Career']);
    $matchingPriority = ProjectFieldOption::factory()->for($priorityField, 'field')->create(['name' => '3']);
    $otherPriority = ProjectFieldOption::factory()->for($priorityField, 'field')->create(['name' => '1']);
    $newer = Issue::factory()->create(['title' => 'Resume Newer', 'state' => 'CLOSED', 'github_number' => 100]);
    $older = Issue::factory()->for($newer->repository, 'repository')->create(['title' => 'Resume Older', 'state' => 'CLOSED', 'github_number' => 2]);
    $wrongPriority = Issue::factory()->for($newer->repository, 'repository')->create(['title' => 'Resume wrong priority', 'state' => 'CLOSED']);
    $wrongArea = Issue::factory()->for($newer->repository, 'repository')->create(['title' => 'Resume wrong area', 'state' => 'CLOSED']);
    $lesson = Label::factory()->for($newer->repository, 'repository')->create(['name' => 'lesson']);
    $next = Label::factory()->for($newer->repository, 'repository')->create(['name' => 'next']);
    foreach ([$newer, $older, $wrongPriority, $wrongArea] as $issue) {
        $issue->labels()->attach([$lesson->id, $next->id]);
    }
    ProjectItem::factory()->for($project, 'project')->for($newer, 'issue')->create(['group_option_id' => $group->id, 'priority_option_id' => $matchingPriority->id]);
    ProjectItem::factory()->for($project, 'project')->for($older, 'issue')->create(['group_option_id' => $group->id, 'priority_option_id' => $matchingPriority->id]);
    ProjectItem::factory()->for($project, 'project')->for($wrongPriority, 'issue')->create(['group_option_id' => $group->id, 'priority_option_id' => $otherPriority->id]);
    ProjectItem::factory()->for($otherProject, 'project')->for($wrongArea, 'issue')->create();

    $query = http_build_query([
        'area' => $project->id, 'view' => 'knowledge', 'q' => 'resume', 'state' => 'ALL',
        'group' => $group->id, 'priority' => 3, 'sortBy' => 'newest_first',
    ]).'&labels[]=next';

    // Assertions target the task-tree row marker (data-issue-number) rather than raw titles: the
    // unrelated "Add task" modal's parent-picker lists up to 100 issues regardless of the active
    // filters, so titles alone would appear on the page either way.
    $this->actingAs(User::factory()->create())->get('/?'.$query)
        ->assertOk()
        ->assertSeeHtmlInOrder(['data-issue-number="100"', 'data-issue-number="2"'])
        ->assertDontSeeHtml('data-issue-number="'.$wrongPriority->github_number.'"')
        ->assertDontSeeHtml('data-issue-number="'.$wrongArea->github_number.'"');

    // Without the query string, the defaults (view=tasks, state=OPEN) hide these CLOSED/knowledge-labeled
    // issues entirely -- confirming the first request's visibility came from the URL, not always-on.
    $this->actingAs(User::factory()->create())->get('/')
        ->assertOk()
        ->assertDontSeeHtml('data-issue-number="100"')
        ->assertDontSeeHtml('data-issue-number="2"');
});

it('refreshes the workspace from GitHub through the existing sync action', function (): void {
    config(['github.token' => 'test-token']);
    $sync = Mockery::mock(SyncGitHub::class);
    $sync->shouldReceive('handle')->once()->with('test-token', true);
    app()->instance(SyncGitHub::class, $sync);

    Livewire::actingAs(User::factory()->create())->test('top-bar')
        ->call('refreshFromGitHub')
        ->assertSet('refreshMessage', 'Updated from GitHub just now.')
        ->assertSet('refreshError', null)
        ->assertSee('Updated from GitHub just now.');
});

it('explains how to enable in-app refresh without a server-side credential', function (): void {
    config(['github.token' => null]);

    Livewire::actingAs(User::factory()->create())->test('top-bar')
        ->call('refreshFromGitHub')
        ->assertSet('refreshError', 'In-app refresh needs a server-side GitHub token. Set GITHUB_TOKEN and try again.')
        ->assertSet('refreshMessage', null)
        ->assertSee('In-app refresh needs a server-side GitHub token. Set GITHUB_TOKEN and try again.');
});

it('renders the workspace page together with the shared top bar', function (): void {
    Issue::factory()->create(['title' => 'Still here']);

    $this->actingAs(User::factory()->create())->get('/')
        ->assertOk()
        ->assertSee('Still here')
        ->assertSeeHtml('aria-label="Settings"');
});

it('force-clears the scroll lock as a safety net against Flux\'s own inline-style lock getting stuck open', function (): void {
    // Flux's <flux:modal> sets document.documentElement.style.overflow (and paddingRight) via its own
    // JS lock/unlock reference counter, independent of our overflow-hidden class toggle -- if that
    // counter desyncs (e.g. Livewire's morph removes a modal before Flux's own cleanup runs), the
    // inline style is stuck even though our tracked *Open state correctly says nothing is open.
    $issue = Issue::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSeeHtml("removeProperty('overflow')")
        ->assertSeeHtml("removeProperty('padding-right')")
        ->assertDontSeeHtml('x-trap.inert.noscroll');
});

it('reports a refresh failure from the shared top bar without crashing', function (): void {
    config(['github.token' => 'test-token']);
    $sync = Mockery::mock(SyncGitHub::class);
    $sync->shouldReceive('handle')->once()->andThrow(new GitHubSyncException('GitHub HTTP 429; check access or retry later.'));
    app()->instance(SyncGitHub::class, $sync);

    // Refresh now lives on its own persistent top-bar component (resources/views/livewire/top-bar.blade.php),
    // separate from the workspace page's own component — a failure here can no longer reset workspace state.
    Livewire::actingAs(User::factory()->create())->test('top-bar')
        ->call('refreshFromGitHub')
        ->assertSet('refreshError', 'GitHub HTTP 429; check access or retry later.')
        ->assertSee('GitHub HTTP 429; check access or retry later.');
});

it('hides the mobile project-settings item until a project area is selected, then shows it reactively', function (): void {
    Livewire::actingAs(User::factory()->create())->test('top-bar')
        ->assertDontSeeHtml("dispatch('open-project-settings')")
        ->call('updateCurrentArea', 5)
        ->assertSeeHtml("dispatch('open-project-settings')");
});

it('picks up the initial project area from the URL so the mobile item is correct on first load', function (): void {
    // A genuine full-page GET, not Livewire::test() -- the component's mount() reads request()->query()
    // directly only outside of Livewire's own AJAX request cycle, so a component-level test wouldn't
    // exercise this path at all.
    $project = GitHubProject::factory()->create();

    $this->actingAs(User::factory()->create())->get('/?area='.$project->id)
        ->assertOk()
        ->assertSeeHtml("dispatch('open-project-settings')");
});

it('quickly captures a task locally without calling GitHub', function (): void {
    GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    Http::fake();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('newTitle', '')
        ->assertSet('captureError', null)
        ->assertSet('captureOpen', false);

    expect(Issue::count())->toBe(1);
    expect(GitHubPushQueueItem::where('operation', 'create_issue')->count())->toBe(1);
    Http::assertNothingSent();
});

it('keeps a quick-capture draft when the repository is not available locally', function (): void {
    Http::fake();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('newTitle', 'Capture this')
        ->assertSet('captureError', 'The repository is not configured or not available locally. Refresh and try again.')
        ->assertSee('The repository is not configured or not available locally.');
});

it('requires a title before quick capture', function (): void {
    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('capture')
        ->assertHasErrors(['newTitle' => 'required']);
});

it('places a quick-captured task in its selected area locally', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects', 'github_node_id' => 'P_test']);
    GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    Http::fake();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('captureArea', $project->id)
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('captureError', null);

    expect(Issue::count())->toBe(1);
    $issue = Issue::first();
    expect(ProjectItem::where('project_id', $project->id)->where('issue_id', $issue->id)->count())->toBe(1);
    expect(GitHubPushQueueItem::where('operation', 'add_project_membership')->count())->toBe(1);
    Http::assertNothingSent();
});

it('retains a quick-capture draft when the selected area becomes unavailable during capture', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects', 'is_available' => false]);
    GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    Http::fake();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('captureArea', $project->id)
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('newTitle', 'Capture this')
        ->assertSet('captureError', 'The selected area is no longer available. Refresh and try again.');

    expect(Issue::count())->toBe(0);
    expect(GitHubPushQueueItem::count())->toBe(0);
});

it('retains a quick-capture draft when its selected area is no longer available', function (): void {
    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('captureArea', 999_999)
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('newTitle', 'Capture this')
        ->assertSet('captureError', 'The selected area is no longer available. Refresh and try again.');

    expect(Issue::count())->toBe(0)
        ->and(GitHubPushQueueItem::count())->toBe(0);
});

it('uses a selected organizational parent as the default for quick capture', function (): void {
    $parent = Issue::factory()->create(['title' => 'Career']);
    $label = Label::factory()->for($parent->repository, 'repository')->create(['name' => 'parent']);
    $parent->labels()->attach($label);
    GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    Http::fake();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('selected', $parent->id)
        ->assertSet('captureParent', $parent->id)
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('captureError', null);

    expect(Issue::count())->toBe(2);
    $child = Issue::where('title', 'Capture this')->first();
    expect($child->parent_issue_id)->toBe($parent->id);
    expect(GitHubPushQueueItem::where('operation', 'set_issue_parent')->count())->toBe(1);
    Http::assertNothingSent();
});

it('creates an issue with a parent relationship set locally', function (): void {
    $parent = Issue::factory()->create(['title' => 'Career']);
    $label = Label::factory()->for($parent->repository, 'repository')->create(['name' => 'parent']);
    $parent->labels()->attach($label);
    GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    Http::fake();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('captureParent', $parent->id)
        ->set('newTitle', 'Capture this')
        ->call('capture')
        ->assertSet('newTitle', '')
        ->assertSet('captureError', null);

    expect(Issue::count())->toBe(2);
    $child = Issue::where('title', 'Capture this')->first();
    expect($child->parent_issue_id)->toBe($parent->id);
    expect($child->sibling_position)->toBe(1);
    expect(GitHubPushQueueItem::where('operation', 'set_issue_parent')->count())->toBe(1);
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

it('shows an always-present Clear filters button only once a filter is actually applied', function (): void {
    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->assertDontSee('Clear filters')
        ->set('search', 'anything')->assertSee('Clear filters');

    $component->call('clearFilters')->assertDontSee('Clear filters')->assertSet('search', '');
});

it('treats group, priority, sort, labels, and state as active filters for the Clear filters button', function (): void {
    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace');

    $component->set('group', 1)->assertSee('Clear filters')->call('clearFilters')->assertDontSee('Clear filters');
    $component->set('priority', 3)->assertSee('Clear filters')->call('clearFilters')->assertDontSee('Clear filters');
    $component->set('sortBy', 'priority')->assertSee('Clear filters')->call('clearFilters')->assertDontSee('Clear filters');
    $component->call('toggleLabel', 'next')->assertSee('Clear filters')->call('clearFilters')->assertDontSee('Clear filters');
    $component->set('state', 'CLOSED')->assertSee('Clear filters')->call('clearFilters')->assertDontSee('Clear filters');
});

it('renders an actual Title field in both the capture and edit forms, not just a bindable property', function (): void {
    // Regression test: a bare {{ }} expression inside a <flux:input> tag (used to conditionally add
    // `autofocus`) silently broke Blade's component-tag compiler, so the tag printed as literal dead
    // text instead of rendering an input — invisible to tests that only ->set() the property, since
    // that works regardless of whether the field is actually rendered on the page.
    $issue = Issue::factory()->create();

    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('openCapture')->assertSeeHtml('wire:model="newTitle"')->assertDontSeeHtml('<flux:input');

    $component->call('cancelEdit')->set('selected', $issue->id)->call('beginEdit')
        ->assertSeeHtml('wire:model="editTitle"')->assertDontSeeHtml('<flux:input');
});

it('opens task capture with the current Project and Group preselected', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)->set('group', $group->id)->call('openCapture')
        ->assertSet('captureOpen', true)->assertSet('captureArea', $project->id)->assertSet('captureGroup', $group->id)
        ->assertDontSee('New:')->assertSee('Search task title or #number')->assertSee('Search or create a label')
        ->call('selectNewCaptureGroup', 'Freelance')->assertSee('New: Freelance');
});

it('filters the capture Group and Labels pickers by their own search term', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);
    ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Home']);
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $urgent = Label::factory()->for($repository, 'repository')->create(['name' => 'urgent']);
    $documentation = Label::factory()->for($repository, 'repository')->create(['name' => 'documentation']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)->call('openCapture')
        ->assertSee('Career')->assertSee('Home')
        ->assertSeeHtml("toggleCaptureLabel({$urgent->id})")->assertSeeHtml("toggleCaptureLabel({$documentation->id})")
        ->set('captureGroupSearch', 'car')->assertSee('Career')->assertDontSee('Home')
        ->set('captureLabelSearch', 'doc')
        ->assertSeeHtml("toggleCaptureLabel({$documentation->id})")->assertDontSeeHtml("toggleCaptureLabel({$urgent->id})");
});

it('selects a newly-created capture Group by name, then lets picking an existing option override it', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $existing = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)->call('openCapture')
        ->call('selectNewCaptureGroup', 'Freelance')
        ->assertSet('captureGroup', -1)->assertSet('captureNewGroup', 'Freelance')
        ->set('captureGroup', $existing->id)
        ->assertSet('captureGroup', $existing->id);
});

it('queues several new capture labels, deduplicating case-insensitively, and lets one be removed before submit', function (): void {
    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')->call('openCapture')
        ->call('addCaptureNewLabel', 'urgent')->assertSet('captureNewLabels', ['urgent'])
        ->call('addCaptureNewLabel', 'Urgent')->assertSet('captureNewLabels', ['urgent'])
        ->call('addCaptureNewLabel', 'blocked')->assertSet('captureNewLabels', ['urgent', 'blocked'])
        ->assertSee('New: urgent')->assertSee('New: blocked');

    $component->call('removeCaptureNewLabel', 0)->assertSet('captureNewLabels', ['blocked'])->assertDontSee('New: urgent');
});

it('captures a task creating every queued new label at once', function (): void {
    GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('newTitle', 'Multi-label task')
        ->call('addCaptureNewLabel', 'urgent')->call('addCaptureNewLabel', 'blocked')
        ->call('capture')->assertSet('captureNewLabels', []);

    $issue = Issue::query()->where('title', 'Multi-label task')->sole();
    expect($issue->labels()->pluck('name')->all())->toEqualCanonicalizing(['urgent', 'blocked']);
});

it('captures a task with a description without calling GitHub', function (): void {
    GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    Http::fake();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('newTitle', 'Capture this')->set('newBody', 'Useful context')->call('capture')
        ->assertSet('newTitle', '')->assertSet('newBody', '')->assertSet('captureOpen', false);

    expect(Issue::count())->toBe(1);
    $issue = Issue::first();
    expect($issue->body)->toBe('Useful context');
    Http::assertNothingSent();
});

it('edits a selected task title and description locally without calling GitHub', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $issue = Issue::factory()->for($repository, 'repository')->create(['title' => 'Old title', 'body' => 'Old notes']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->assertSet('editingIssue', true)->assertSet('editTitle', 'Old title')->assertSet('editBody', 'Old notes')
        ->set('editTitle', 'New title')->set('editBody', 'New notes')->call('saveIssue')
        ->assertSet('editingIssue', false)->assertSet('editError', null)->assertSee('New title')->assertSee('New notes');

    expect($issue->refresh())->title->toBe('New title')->body->toBe('New notes');
    expect(GitHubPushQueueItem::query()->where('operation', 'update_issue_body')->where('target_id', $issue->id)->count())->toBe(1);
});

it('keeps the edit form open with an error instead of silently discarding the edit when a new Group has no Area', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $issue = Issue::factory()->for($repository, 'repository')->create(['title' => 'Old title']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editTitle', 'Should not save')->set('editNewGroup', 'New Group')->call('saveIssue')
        ->assertSet('editingIssue', true)->assertSet('editError', 'Choose an area before creating a Group.');

    expect($issue->refresh()->title)->toBe('Old title');
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('replaces task labels locally and enqueues the add/remove diff, not a full resync', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $issue = Issue::factory()->for($repository, 'repository')->create();
    $kept = Label::factory()->for($repository, 'repository')->create(['name' => 'keep']);
    $removed = Label::factory()->for($repository, 'repository')->create(['name' => 'drop']);
    $added = Label::factory()->for($repository, 'repository')->create(['name' => 'new']);
    $issue->labels()->attach([$kept->id, $removed->id]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editLabels', [$kept->id, $added->id])->call('saveIssue')
        ->assertSet('editingIssue', false);

    expect($issue->labels()->pluck('labels.id')->sort()->values()->all())->toBe(collect([$kept->id, $added->id])->sort()->values()->all());
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'set_issue_labels')->where('target_id', $issue->id)->sole();
    expect($enqueued->payload)->toBe(['add_label_ids' => [$added->id], 'remove_label_ids' => [$removed->id]]);
});

it('saves an edit creating several new labels at once, lowercased and deduplicated case-insensitively', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $issue = Issue::factory()->for($repository, 'repository')->create();
    $existing = Label::factory()->for($repository, 'repository')->create(['name' => 'urgent']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')
        ->call('addEditNewLabel', 'Urgent')->assertSet('editNewLabels', ['urgent'])
        ->call('addEditNewLabel', 'URGENT')->assertSet('editNewLabels', ['urgent'])
        ->call('addEditNewLabel', 'Blocked')->assertSet('editNewLabels', ['urgent', 'blocked'])->assertSee('New: blocked')
        ->call('removeEditNewLabel', 0)->assertSet('editNewLabels', ['blocked'])
        ->call('saveIssue')->assertSet('editingIssue', false);

    expect(Label::query()->count())->toBe(2)
        ->and($issue->labels()->pluck('name')->all())->toEqualCanonicalizing(['blocked']);
    expect($existing->fresh())->not->toBeNull();
});

it('sets a new parent on an existing task and enqueues it', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $parent = Issue::factory()->for($repository, 'repository')->create();
    $issue = Issue::factory()->for($repository, 'repository')->create(['parent_issue_id' => null]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editParent', $parent->id)->call('saveIssue');

    expect($issue->refresh()->parent_issue_id)->toBe($parent->id);
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'set_issue_parent')->where('target_id', $issue->id)->sole();
    expect($enqueued->payload)->toBe(['parent_issue_id' => $parent->id]);
});

it('removes a previously pushed parent and enqueues its removal with the parent GitHub id', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $parent = Issue::factory()->for($repository, 'repository')->create(['github_node_id' => 'I_parent']);
    $issue = Issue::factory()->for($repository, 'repository')->create(['parent_issue_id' => $parent->id, 'github_parent_node_id' => 'I_parent']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editParent', 0)->call('saveIssue');

    expect($issue->refresh())->parent_issue_id->toBeNull()->github_parent_node_id->toBeNull();
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'remove_issue_parent')->where('target_id', $issue->id)->sole();
    expect($enqueued->payload)->toBe(['parent_github_node_id' => 'I_parent']);
});

it('removes an unpushed parent locally without enqueueing a remote removal', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $parent = Issue::factory()->for($repository, 'repository')->create(['github_node_id' => null]);
    $issue = Issue::factory()->for($repository, 'repository')->create(['parent_issue_id' => $parent->id, 'github_parent_node_id' => null]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editParent', 0)->call('saveIssue');

    expect($issue->refresh()->parent_issue_id)->toBeNull();
    expect(GitHubPushQueueItem::query()->where('operation', 'remove_issue_parent')->count())->toBe(0);
});

it('moves a task to a different Project, deleting the previously pushed membership and adding the new one', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $issue = Issue::factory()->for($repository, 'repository')->create();
    $oldProject = GitHubProject::factory()->create();
    $newProject = GitHubProject::factory()->create();
    $oldItem = ProjectItem::factory()->for($oldProject, 'project')->for($issue, 'issue')->create(['github_node_id' => 'PVTI_old']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editArea', $newProject->id)->call('saveIssue');

    expect(ProjectItem::query()->where('project_id', $newProject->id)->where('issue_id', $issue->id)->exists())->toBeTrue();
    $deleteEnqueued = GitHubPushQueueItem::query()->where('operation', 'delete_project_item')->where('target_id', $oldItem->id)->sole();
    $newItem = ProjectItem::query()->where('project_id', $newProject->id)->where('issue_id', $issue->id)->sole();
    $addEnqueued = GitHubPushQueueItem::query()->where('operation', 'add_project_membership')->where('target_id', $newItem->id)->sole();
    expect($deleteEnqueued)->not->toBeNull()->and($addEnqueued)->not->toBeNull();
});

it('moves a task off a Project that was never pushed, without enqueueing a remote deletion', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $issue = Issue::factory()->for($repository, 'repository')->create();
    $oldProject = GitHubProject::factory()->create();
    $oldItem = ProjectItem::factory()->for($oldProject, 'project')->for($issue, 'issue')->create(['github_node_id' => null]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editArea', 0)->call('saveIssue');

    expect($oldItem->refresh()->is_available)->toBeFalse();
    expect(GitHubPushQueueItem::query()->where('operation', 'delete_project_item')->count())->toBe(0);
});

it('sets and then clears a Group on an existing Project membership', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $issue = Issue::factory()->for($repository, 'repository')->create();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['github_node_id' => 'PVTI_1', 'group_option_id' => null]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editArea', $project->id)->set('editGroup', $group->id)->call('saveIssue');

    expect($item->refresh()->group_option_id)->toBe($group->id);
    expect(GitHubPushQueueItem::query()->where('operation', 'set_project_item_group')->where('target_id', $item->id)->count())->toBe(1);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editGroup', 0)->call('saveIssue');

    expect($item->refresh()->group_option_id)->toBeNull();
    expect(GitHubPushQueueItem::query()->where('operation', 'clear_project_item_group')->where('target_id', $item->id)->count())->toBe(1);
});

it('resets editing when the issue becomes unavailable during edit', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $issue = Issue::factory()->for($repository, 'repository')->create(['title' => 'Old title', 'is_available' => true]);

    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->assertSet('editingIssue', true);

    $issue->update(['is_available' => false]);

    $component->call('saveIssue')
        ->assertSet('editingIssue', false)->assertSet('selected', 0);
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

it('renames a Group from Project settings', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)->call('openProjectSettings')
        ->assertSee('Career')
        ->call('beginRenameGroup', $group->id)->assertSet('managingGroupId', $group->id)->assertSet('managingGroupName', 'Career')
        ->set('managingGroupName', 'Freelance')->call('saveGroupRename')
        ->assertSet('managingGroupId', 0)->assertSee('Freelance');

    expect($group->refresh()->name)->toBe('Freelance');
});

it('cancels an in-progress Group rename without saving it', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)->call('openProjectSettings')
        ->call('beginRenameGroup', $group->id)->set('managingGroupName', 'Something else')
        ->call('cancelRenameGroup')->assertSet('managingGroupId', 0);

    expect($group->refresh()->name)->toBe('Career');
});

it('shows a validation error inline instead of closing Project settings when a Group rename collides', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);
    $home = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Home']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)->call('openProjectSettings')
        ->call('beginRenameGroup', $home->id)->set('managingGroupName', 'career')->call('saveGroupRename')
        ->assertSet('projectSettingsOpen', true)->assertSee('Another Group in this area already has this name.');

    expect($home->refresh()->name)->toBe('Home');
});

it('deletes a Group from Project settings, clearing the active filter and any pending selection if it matched', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)->set('group', $group->id)->call('openProjectSettings')
        ->assertSee('Career')
        ->call('deleteGroupOption', $group->id)
        ->assertSet('group', 0)->assertDontSee('Career');

    expect(ProjectFieldOption::query()->find($group->id))->toBeNull();
});

it('opens Manage labels via the cross-component event the gear menu dispatches, and renames a label', function (): void {
    Http::fake();
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $label = Label::factory()->for($repository, 'repository')->create(['name' => 'urgent']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->dispatch('open-manage-labels')
        ->assertSet('manageLabelsOpen', true)->assertSee('urgent')
        ->call('beginRenameLabel', $label->id)->assertSet('managingLabelName', 'urgent')
        ->set('managingLabelName', 'Blocked')->call('saveLabelRename')
        ->assertSet('managingLabelId', 0)->assertSee('blocked');

    expect($label->refresh()->name)->toBe('blocked');
});

it('shows a validation error inline instead of closing Manage labels when a label rename collides', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    Label::factory()->for($repository, 'repository')->create(['name' => 'urgent']);
    $blocked = Label::factory()->for($repository, 'repository')->create(['name' => 'blocked']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('openManageLabels')
        ->call('beginRenameLabel', $blocked->id)->set('managingLabelName', 'Urgent')->call('saveLabelRename')
        ->assertSet('manageLabelsOpen', true)->assertSee('Another label already has this name.');

    expect($blocked->refresh()->name)->toBe('blocked');
});

it('deletes a label from Manage labels, clearing it from the active filter and any pending selections', function (): void {
    Http::fake();
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $label = Label::factory()->for($repository, 'repository')->create(['name' => 'urgent']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('toggleLabel', 'urgent')->assertSet('labels', ['urgent'])
        ->call('openManageLabels')->assertSee('urgent')
        ->call('deleteLabelOption', $label->id)
        ->assertSet('labels', [])->assertDontSee('urgent');

    expect($label->refresh()->is_available)->toBeFalse();
});

it('switches between Daily and an area via the navigation actions the mobile pill row uses', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('daily')->assertSet('view', 'daily')->assertSet('area', 0)
        ->call('chooseArea', $project->id)->assertSet('view', 'tasks')->assertSet('area', $project->id);
});

it('closes a selected task locally and enqueues the GitHub close operation', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('Mark done')->call('closeIssue')->assertDontSee('Mark done');

    expect($issue->refresh())->state->toBe('CLOSED');
    expect(GitHubPushQueueItem::query()->where('operation', 'close_issue')->where('target_id', $issue->id)->count())->toBe(1);
});

it('shows the children choice only when the selected task has sub-tasks', function (): void {
    $parent = Issue::factory()->create();
    $child = Issue::factory()->for($parent, 'parent')->for($parent->repository, 'repository')->create();
    $leaf = Issue::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('selected', $leaf->id)->call('openDeleteConfirm')
        ->assertSet('deletingIssueChildrenCount', 0)
        ->set('selected', $parent->id)->call('openDeleteConfirm')
        ->assertSet('deletingIssueChildrenCount', 1);

    expect($child->exists)->toBeTrue();
});

it('deletes a task locally, closes its detail panel, and hides it from the normal task list', function (): void {
    $issue = Issue::factory()->create(['title' => 'Delete me']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('selected', $issue->id)->call('openDeleteConfirm')
        ->call('confirmDelete', false)
        ->assertSet('selected', 0)
        ->assertSet('deleteConfirmOpen', false)
        ->assertDontSee('Delete me');

    expect($issue->refresh()->is_available)->toBeFalse()
        ->and(GitHubPushQueueItem::query()->where('operation', 'delete_issue')->where('target_id', $issue->id)->exists())->toBeTrue();
});

it('finds a deleted task via the Deleted view and restores it at any time', function (): void {
    $issue = Issue::factory()->create(['title' => 'Bring this back']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('selected', $issue->id)->call('openDeleteConfirm')->call('confirmDelete', false)
        ->set('view', 'deleted')
        ->assertSee('Bring this back')
        ->call('restoreIssue', $issue->id)
        ->assertSee('Restored "Bring this back".');

    expect($issue->refresh()->is_available)->toBeTrue()
        ->and(GitHubPushQueueItem::query()->where('operation', 'delete_issue')->where('target_id', $issue->id)->exists())->toBeFalse();
});

it('adds and edits comments locally and enqueues GitHub operations', function (): void {
    $issue = Issue::factory()->create();
    $comment = Comment::factory()->for($issue, 'issue')->create(['body' => 'Old comment']);

    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->set('newCommentBody', 'New comment')->call('addComment')->assertSet('newCommentBody', '')->assertSee('New comment');

    $newComment = Comment::query()->where('issue_id', $issue->id)->where('body', 'New comment')->firstOrFail();
    expect(GitHubPushQueueItem::query()->where('operation', 'create_comment')->where('target_id', $newComment->id)->count())->toBe(1);

    $component
        ->call('beginEditComment', $comment->id)->assertSet('editingComment', $comment->id)->assertSet('editCommentBody', 'Old comment')
        ->set('editCommentBody', 'Edited comment')->call('saveComment')->assertSet('editingComment', 0)->assertSee('Edited comment');

    expect($comment->refresh())->body->toBe('Edited comment');
    expect(GitHubPushQueueItem::query()->where('operation', 'update_comment')->where('target_id', $comment->id)->count())->toBe(1);
});

it('refuses to save a comment edit that changed since editing began', function (): void {
    $issue = Issue::factory()->create();
    $comment = Comment::factory()->for($issue, 'issue')->create(['body' => 'Original', 'revision' => 1]);

    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEditComment', $comment->id);

    $comment->update(['body' => 'Changed elsewhere', 'revision' => 2]);

    $component->set('editCommentBody', 'My conflicting edit')->call('saveComment')
        ->assertSet('commentError', 'This comment changed since you started editing it. Refresh and try again.');

    expect($comment->fresh()->body)->toBe('Changed elsewhere');
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
        ->assertDontSee('New:')->assertSee('Search or create a label')
        ->call('selectNewEditGroup', 'Freelance')->assertSee('New: Freelance');
});

it('closes via the single Close button, saving whatever is in the comment box as the note', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSeeHtml("closeWithComment('".CloseTodoIssue::REASON_COMPLETED."')")
        ->set('newCommentBody', 'Shipped it.')->call('closeWithComment', CloseTodoIssue::REASON_COMPLETED)
        ->assertSet('newCommentBody', '')->assertSee('Shipped it.');

    expect($issue->refresh())->state->toBe('CLOSED')->state_reason->toBe('COMPLETED')
        ->and(Comment::query()->where('issue_id', $issue->id)->sole()->kind)->toBe(Comment::KIND_CLOSING);
});

it('closes via the single Close button with an empty comment box, leaving no closing note', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('closeWithComment', CloseTodoIssue::REASON_COMPLETED);

    expect($issue->refresh())->state->toBe('CLOSED')->state_reason->toBe('COMPLETED');
    expect(Comment::query()->where('issue_id', $issue->id)->count())->toBe(0);
});

it('still supports closing as not planned at the method level, even though no button currently reaches it', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('closeWithComment', CloseTodoIssue::REASON_NOT_PLANNED);

    expect($issue->refresh())->state->toBe('CLOSED')->state_reason->toBe('NOT_PLANNED');
});

it('rejects a tampered close reason without closing the issue', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('closeWithComment', 'DONE')->assertSee('Not a valid close reason.');

    expect($issue->refresh())->state->toBe('OPEN');
});

it('puts Close and Add comment on the same row, styles Close red, and hides it once the issue is closed', function (): void {
    $open = Issue::factory()->create(['state' => 'OPEN']);
    $closed = Issue::factory()->create(['state' => 'CLOSED']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('selected', $open->id)
        ->assertSeeHtml("closeWithComment('".CloseTodoIssue::REASON_COMPLETED."')")->assertSeeHtml('wire:submit="addComment"')
        ->assertSeeHtml('text-red-600')
        ->assertSeeHtml('wire:confirm=')
        ->set('selected', $closed->id)
        ->assertDontSeeHtml("closeWithComment('".CloseTodoIssue::REASON_COMPLETED."')")->assertSee('Add comment');
});

it('shows the closing note as a highlighted block for a closed issue, and not for an open one', function (): void {
    $closed = Issue::factory()->create(['state' => 'CLOSED', 'state_reason' => 'COMPLETED']);
    Comment::factory()->for($closed, 'issue')->closing()->create(['body' => 'All done here.', 'references' => ['#42']]);
    $open = Issue::factory()->create(['state' => 'OPEN']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('selected', $closed->id)->assertSee('All done here.')->assertSee('#42')->assertSee('Completed')
        ->set('selected', $open->id)->assertDontSee('All done here.');
});

it('does not show a highlighted closing block for a closed issue with no closing note', function (): void {
    $issue = Issue::factory()->create(['state' => 'CLOSED', 'state_reason' => null]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('selected', $issue->id)->assertDontSee('Not planned');
});

it('creates a label from the Manage labels popup and shows it in the list', function (): void {
    Http::fake();
    GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('openManageLabels')
        ->set('newManageLabelName', 'Waiting on Vendor')->call('createManageLabel')
        ->assertSet('newManageLabelName', '')->assertSee('waiting on vendor');

    expect(Label::query()->where('name', 'waiting on vendor')->exists())->toBeTrue();
});

it('shows a validation error inline instead of closing Manage labels when creating a duplicate label', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    Label::factory()->for($repository, 'repository')->create(['name' => 'bug']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('openManageLabels')
        ->set('newManageLabelName', 'Bug')->call('createManageLabel')
        ->assertSet('manageLabelsOpen', true)->assertSee('A label with this name already exists.');

    expect(Label::query()->where('name', 'bug')->count())->toBe(1);
});

it('clears the new-label field and any error each time Manage labels is opened', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    Label::factory()->for($repository, 'repository')->create(['name' => 'bug']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('openManageLabels')->set('newManageLabelName', 'Bug')->call('createManageLabel')
        ->assertSee('A label with this name already exists.')
        ->call('openManageLabels')
        ->assertSet('newManageLabelName', '')->assertDontSee('A label with this name already exists.');
});

it('hides Manage labels from the labeled top-bar\'s settings popup, since the sidebar has its own link', function (): void {
    Livewire::actingAs(User::factory()->create())->test('top-bar', ['variant' => 'labeled'])
        ->assertDontSee('Manage labels');
});

it('keeps Manage labels in the icon top-bar\'s settings popup for mobile, which has no sidebar', function (): void {
    Livewire::actingAs(User::factory()->create())->test('top-bar')
        ->assertSee('Manage labels');
});

it('opens Manage labels from the sidebar link', function (): void {
    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->assertSet('manageLabelsOpen', false)
        ->call('openManageLabels')
        ->assertSet('manageLabelsOpen', true);
});

it('lets the Manage labels popup scroll internally so a long label list never hides the Close button off-screen', function (): void {
    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('openManageLabels')
        ->assertSeeHtml('data-modal="manage-labels"')
        ->assertSeeHtml('data-flux-modal-overflow');
});

it('asks for confirmation before Refresh from GitHub, the same way other consequential actions do', function (): void {
    Livewire::actingAs(User::factory()->create())->test('top-bar')
        ->assertSeeHtml('wire:click="refreshFromGitHub"')
        ->assertSeeHtml('wire:confirm=');
});

it('reopens a closed task via the header icon, with confirmation, clearing the reason and closing block', function (): void {
    // The note itself isn't expected to vanish -- it moves from the highlighted closing block into
    // the ordinary thread once reopened (see the next test); "Completed" is what should disappear,
    // since state_reason is cleared and there's no longer a current close to highlight.
    $issue = Issue::factory()->create(['state' => 'CLOSED', 'state_reason' => 'COMPLETED']);
    Comment::factory()->for($issue, 'issue')->closing()->create(['body' => 'Turned out fine.']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSeeHtml('wire:click="reopenIssue"')->assertSeeHtml('wire:confirm=')
        ->assertSee('Turned out fine.')->assertSee('Completed')
        ->call('reopenIssue')
        ->assertDontSee('Completed');

    expect($issue->refresh())->state->toBe('OPEN')->state_reason->toBeNull();
});

it('shows the old closing note back in the ordinary thread once reopened', function (): void {
    $issue = Issue::factory()->create(['state' => 'CLOSED']);
    Comment::factory()->for($issue, 'issue')->closing()->create(['body' => 'First close note.']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('reopenIssue')
        ->assertSee('First close note.');
});

it('hides the Reopen icon for an open task and the Mark done icon for a closed one', function (): void {
    $open = Issue::factory()->create(['state' => 'OPEN']);
    $closed = Issue::factory()->create(['state' => 'CLOSED']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('selected', $open->id)->assertSeeHtml('wire:click="closeIssue"')->assertDontSeeHtml('wire:click="reopenIssue"')
        ->set('selected', $closed->id)->assertSeeHtml('wire:click="reopenIssue"')->assertDontSeeHtml('wire:click="closeIssue"');
});

it('offers a Reopen button beside Add comment at the bottom of a closed task, and Close instead for an open one', function (): void {
    $open = Issue::factory()->create(['state' => 'OPEN']);
    $closed = Issue::factory()->create(['state' => 'CLOSED']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->set('selected', $closed->id)->assertSeeHtml('wire:submit="addComment"')->assertSeeInOrder(['Add a comment', 'Reopen', 'Add comment'])
        ->assertDontSeeHtml("closeWithComment('".CloseTodoIssue::REASON_COMPLETED."')")
        ->call('reopenIssue')->assertDontSeeHtml('wire:click="reopenIssue"')->assertSeeHtml("closeWithComment('".CloseTodoIssue::REASON_COMPLETED."')")
        ->set('selected', $open->id)->assertDontSeeHtml('wire:click="reopenIssue"');
});

it('shows Closed for a closed row and Modified for an open one in the task list', function (): void {
    config(['dibs.timezone' => 'America/Los_Angeles']);
    Issue::factory()->create(['title' => 'Open one', 'state' => 'OPEN', 'remote_updated_at' => '2026-09-10 03:00:00']);
    Issue::factory()->create(['title' => 'Closed one', 'state' => 'CLOSED', 'closed_at' => '2026-09-12 20:00:00']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('state', 'ALL')
        ->assertSee('Modified Sep 9, 2026')->assertSee('Closed Sep 12, 2026');
});

it('shows the deletion date on a deleted task in the Deleted view, in the configured timezone', function (): void {
    config(['dibs.timezone' => 'America/Los_Angeles']);
    $issue = Issue::factory()->create(['title' => 'Gone task', 'is_available' => false]);
    $issue->forceFill(['updated_at' => '2026-09-12 03:00:00'])->saveQuietly();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('view', 'deleted')
        ->assertSee('Gone task')->assertSee('Deleted Sep 11, 2026');
});
