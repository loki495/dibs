<?php

declare(strict_types=1);

use App\Actions\ApplyGitHubSnapshot;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubSyncException;

function githubSnapshotFixture(): array
{
    return [
        'repository' => ['id' => 'R1', 'owner' => ['login' => 'loki495'], 'name' => 'Todo', 'nameWithOwner' => 'loki495/Todo', 'url' => 'https://github.com/loki495/Todo', 'isPrivate' => true, 'visibility' => 'PRIVATE', 'updatedAt' => '2026-09-08T12:00:00Z'],
        'labels' => [['id' => 'L1', 'name' => 'parent', 'color' => 'abcdef', 'description' => 'Container']],
        'issues' => [
            ['id' => 'I1', 'number' => 1, 'title' => 'Website', 'body' => '', 'state' => 'OPEN', 'stateReason' => null, 'url' => 'https://github.com/loki495/Todo/issues/1', 'updatedAt' => '2026-09-08T12:00:00Z', 'parent' => null, 'children' => [['id' => 'I2']], 'labels' => [['id' => 'L1']]],
            ['id' => 'I2', 'number' => 2, 'title' => 'Task', 'body' => 'Details', 'state' => 'OPEN', 'stateReason' => null, 'url' => 'https://github.com/loki495/Todo/issues/2', 'updatedAt' => '2026-09-08T12:00:00Z', 'parent' => ['id' => 'I1'], 'children' => [], 'labels' => []],
        ],
        'projects' => [[
            'id' => 'P1', 'owner' => 'loki495', 'number' => 3, 'title' => 'Personal Projects', 'url' => 'https://github.com/users/loki495/projects/3', 'closed' => false, 'public' => false, 'updatedAt' => '2026-09-08T12:00:00Z',
            'fields' => [['id' => 'F1', 'name' => 'Planned', 'dataType' => 'DATE'], ['id' => 'F2', 'name' => 'Priority', 'dataType' => 'SINGLE_SELECT', 'options' => [['id' => 'PO1', 'name' => '1', 'color' => 'ff0000']]]],
            'items' => [['id' => 'PI1', 'type' => 'ISSUE', 'isArchived' => false, 'updatedAt' => '2026-09-08T12:00:00Z', 'content' => ['id' => 'I2'], 'values' => [['field' => ['id' => 'F1'], 'date' => '2026-09-09'], ['field' => ['id' => 'F2'], 'optionId' => 'PO1']]]],
        ]],
    ];
}

it('imports stable identities, native hierarchy, labels and project dates idempotently', function (): void {
    $action = app(ApplyGitHubSnapshot::class);
    $action->handle(githubSnapshotFixture());
    $id = Issue::query()->where('github_node_id', 'I2')->sole()->id;
    $action->handle(githubSnapshotFixture());
    expect(Issue::query()->count())->toBe(2)
        ->and(Issue::query()->findOrFail($id)->parent->github_node_id)->toBe('I1')
        ->and(Issue::query()->where('github_node_id', 'I1')->sole()->labels->sole()->name)->toBe('parent')
        ->and(ProjectItem::query()->sole()->planned_on->toDateString())->toBe('2026-09-09')
        ->and(ProjectItem::query()->sole()->priorityOption?->name)->toBe('1');
});

it('updates renamed records and marks removed membership unavailable without deleting its issue', function (): void {
    $snapshot = githubSnapshotFixture();
    app(ApplyGitHubSnapshot::class)->handle($snapshot);
    $snapshot['labels'][0]['name'] = 'container';
    $snapshot['issues'][1]['title'] = 'Edited in GitHub';
    $snapshot['issues'][1]['parent'] = ['id' => 'UNKNOWN'];
    $snapshot['issues'][0]['children'] = [];
    $snapshot['projects'][0]['items'] = [];
    app(ApplyGitHubSnapshot::class)->handle($snapshot);
    expect(Label::query()->sole()->name)->toBe('container')
        ->and(Issue::query()->where('github_node_id', 'I2')->sole()->title)->toBe('Edited in GitHub')
        ->and(Issue::query()->where('github_node_id', 'I2')->sole()->parent)->toBeNull()
        ->and(ProjectItem::query()->sole()->is_available)->toBeFalse()
        ->and(Issue::query()->where('is_available', true)->count())->toBe(2);
});

it('rolls back a malformed snapshot without modifying the previous good data', function (): void {
    app(ApplyGitHubSnapshot::class)->handle(githubSnapshotFixture());
    $snapshot = githubSnapshotFixture();
    $snapshot['issues'][0]['title'] = 'Should roll back';
    $snapshot['projects'][0]['items'][0]['id'] = null;
    // project_items.github_node_id became nullable for #49's local-first pending rows, so a missing remote
    // id is no longer a DB constraint violation — ApplyGitHubSnapshot must reject it explicitly instead.
    expect(fn () => app(ApplyGitHubSnapshot::class)->handle($snapshot))->toThrow(GitHubSyncException::class, 'without an id');
    expect(Issue::query()->where('github_node_id', 'I1')->sole()->title)->toBe('Website');
});

it('imports comments and marks a locally-removed one unavailable on re-import', function (): void {
    $fixture = githubSnapshotFixture();
    $fixture['issues'][1]['comments'] = [
        ['id' => 'C1', 'body' => 'First', 'author' => ['login' => 'octocat'], 'url' => 'https://github.test/1#c1', 'createdAt' => '2026-09-08T12:00:00Z', 'updatedAt' => '2026-09-08T12:00:00Z'],
    ];
    app(ApplyGitHubSnapshot::class)->handle($fixture);
    $issue = Issue::query()->where('github_node_id', 'I2')->sole();
    expect($issue->comments()->where('is_available', true)->count())->toBe(1)
        ->and($issue->comments()->sole()->body)->toBe('First');

    $fixture['issues'][1]['comments'] = [];
    app(ApplyGitHubSnapshot::class)->handle($fixture);

    expect($issue->comments()->where('is_available', true)->count())->toBe(0);
});

it('rejects a label referenced by an issue but missing from the snapshot label list', function (): void {
    $fixture = githubSnapshotFixture();
    $fixture['issues'][0]['labels'] = [['id' => 'L_MISSING']];

    expect(fn () => app(ApplyGitHubSnapshot::class)->handle($fixture))
        ->toThrow(GitHubSyncException::class, 'Labels changed during import');
});

it('applies a repeat rule from a text-type Project field', function (): void {
    $fixture = githubSnapshotFixture();
    $fixture['projects'][0]['fields'][] = ['id' => 'F3', 'name' => 'Repeat', 'dataType' => 'TEXT'];
    $fixture['projects'][0]['items'][0]['values'][] = ['field' => ['id' => 'F3'], 'text' => 'weekly'];

    app(ApplyGitHubSnapshot::class)->handle($fixture);

    $item = ProjectItem::query()->where('github_node_id', 'PI1')->sole();
    expect($item->repeat_rule)->toBe('weekly');
});

it('rejects a comment with no id instead of silently accepting it', function (): void {
    $snapshot = githubSnapshotFixture();
    $snapshot['issues'][0]['comments'] = [['id' => null, 'body' => 'Hello', 'author' => ['login' => 'loki495'], 'url' => null, 'createdAt' => '2026-09-08T12:00:00Z', 'updatedAt' => '2026-09-08T12:00:00Z']];

    expect(fn () => app(ApplyGitHubSnapshot::class)->handle($snapshot))->toThrow(GitHubSyncException::class, 'comment without an id');
    expect(Issue::query()->count())->toBe(0);
});

it('rejects a label with no id instead of silently accepting it', function (): void {
    $snapshot = githubSnapshotFixture();
    $snapshot['labels'][0]['id'] = null;

    expect(fn () => app(ApplyGitHubSnapshot::class)->handle($snapshot))->toThrow(GitHubSyncException::class, 'label without an id');
    expect(Label::query()->count())->toBe(0);
});

it('rejects a Project field option with no id instead of silently accepting it', function (): void {
    $snapshot = githubSnapshotFixture();
    $snapshot['projects'][0]['fields'][1]['options'][0]['id'] = null;

    expect(fn () => app(ApplyGitHubSnapshot::class)->handle($snapshot))->toThrow(GitHubSyncException::class, 'field option without an id');
    expect(Issue::query()->count())->toBe(0);
});

it('accepts re-added membership with a new GitHub item identity', function (): void {
    $snapshot = githubSnapshotFixture();
    app(ApplyGitHubSnapshot::class)->handle($snapshot);
    $removed = $snapshot;
    $removed['projects'][0]['items'] = [];
    app(ApplyGitHubSnapshot::class)->handle($removed);
    $snapshot['projects'][0]['items'][0]['id'] = 'PI_NEW';
    app(ApplyGitHubSnapshot::class)->handle($snapshot);
    expect(ProjectItem::query()->where('is_available', true)->sole()->github_node_id)->toBe('PI_NEW');
});

it('reconciles a local-first label onto the same name instead of duplicating it, closing #61', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R1']);
    $local = Label::factory()->for($repository, 'repository')->create(['github_node_id' => null, 'name' => 'needs research']);

    $snapshot = githubSnapshotFixture();
    $snapshot['labels'][] = ['id' => 'L2', 'name' => 'needs research', 'color' => 'e4e669', 'description' => 'Workflow marker'];
    app(ApplyGitHubSnapshot::class)->handle($snapshot);

    expect(Label::query()->where('name', 'needs research')->count())->toBe(1)
        ->and($local->fresh()->github_node_id)->toBe('L2')
        ->and($local->fresh()->color)->toBe('e4e669');
});

it('reconciles a local-first label by name case-insensitively', function (): void {
    $repository = GitHubRepository::factory()->create(['github_node_id' => 'R1']);
    Label::factory()->for($repository, 'repository')->create(['github_node_id' => null, 'name' => 'Needs Research']);

    $snapshot = githubSnapshotFixture();
    $snapshot['labels'][] = ['id' => 'L2', 'name' => 'needs research', 'color' => 'e4e669', 'description' => null];
    app(ApplyGitHubSnapshot::class)->handle($snapshot);

    expect(Label::query()->where('github_node_id', 'L2')->count())->toBe(1);
});

it('preserves multiple memberships and non-issue content', function (): void {
    $snapshot = githubSnapshotFixture();
    $second = $snapshot['projects'][0];
    $second['id'] = 'P2';
    $second['number'] = 5;
    $second['fields'] = [];
    $second['items'][0]['id'] = 'PI2';
    $second['items'][0]['values'] = [];
    $second['items'][] = ['id' => 'PI3', 'type' => 'DRAFT_ISSUE', 'isArchived' => false, 'updatedAt' => '2026-09-08T12:00:00Z', 'content' => ['id' => 'DRAFT1', 'title' => 'A draft'], 'values' => []];
    $snapshot['projects'][] = $second;
    app(ApplyGitHubSnapshot::class)->handle($snapshot);
    expect(Issue::query()->where('github_node_id', 'I2')->sole()->projectItems()->count())->toBe(2)
        ->and(ProjectItem::query()->where('github_node_id', 'PI3')->sole()->issue_id)->toBeNull()
        ->and(ProjectItem::query()->where('github_node_id', 'PI3')->sole()->raw_fields_json['content']['title'])->toBe('A draft');
});
