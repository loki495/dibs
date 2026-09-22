<?php

declare(strict_types=1);

use App\Actions\DescribeTodoMetadata;
use App\Models\GitHubProject;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Validation\ValidationException;

/** @return array{work: GitHubProject, personal: GitHubProject, dibs: ProjectFieldOption, career: ProjectFieldOption, five: ProjectFieldOption} */
function metadataFixture(): array
{
    $work = GitHubProject::factory()->create(['title' => 'Work']);
    $personal = GitHubProject::factory()->create(['title' => 'Personal Projects']);

    $workGroups = ProjectField::factory()->for($work, 'project')->create(['semantic_key' => 'group']);
    $personalGroups = ProjectField::factory()->for($personal, 'project')->create(['semantic_key' => 'group']);
    $priorities = ProjectField::factory()->for($personal, 'project')->create(['semantic_key' => 'priority']);

    $career = ProjectFieldOption::factory()->for($workGroups, 'field')->create(['name' => 'Career', 'position' => 1]);
    $dibs = ProjectFieldOption::factory()->for($personalGroups, 'field')->create(['name' => 'Dibs', 'position' => 1]);
    ProjectFieldOption::factory()->for($priorities, 'field')->create(['name' => '4', 'position' => 1]);
    $five = ProjectFieldOption::factory()->for($priorities, 'field')->create(['name' => '5', 'position' => 2]);

    return ['work' => $work, 'personal' => $personal, 'dibs' => $dibs, 'career' => $career, 'five' => $five];
}

it('describes areas, groups, priorities and labels with ids and usage counts', function (): void {
    $f = metadataFixture();
    $open = Issue::factory()->create(['state' => 'OPEN']);
    $closed = Issue::factory()->create(['state' => 'CLOSED']);
    foreach ([$open, $closed] as $issue) {
        ProjectItem::factory()->for($f['personal'], 'project')->for($issue, 'issue')->create(['group_option_id' => $f['dibs']->id]);
    }
    $bug = Label::factory()->create(['name' => 'bug', 'description' => 'A defect', 'color' => 'd73a4a']);
    $open->labels()->attach($bug);
    $closed->labels()->attach($bug);
    $unused = Label::factory()->create(['name' => 'someday']);

    $result = app(DescribeTodoMetadata::class)->handle();

    expect(collect($result['areas'])->firstWhere('id', $f['personal']->id))->toMatchArray(['title' => 'Personal Projects', 'openTaskCount' => 1])
        ->and(collect($result['groups'])->firstWhere('id', $f['dibs']->id))->toMatchArray(['name' => 'Dibs', 'areaId' => $f['personal']->id, 'area' => 'Personal Projects', 'openTaskCount' => 1])
        ->and(collect($result['groups'])->firstWhere('id', $f['career']->id))->toMatchArray(['areaId' => $f['work']->id, 'openTaskCount' => 0])
        ->and(collect($result['priorities'])->pluck('name')->all())->toBe(['4', '5'])
        ->and(collect($result['priorities'])->firstWhere('id', $f['five']->id))->toMatchArray(['areaId' => $f['personal']->id, 'area' => 'Personal Projects'])
        ->and(collect($result['labels'])->firstWhere('id', $bug->id))->toMatchArray(['name' => 'bug', 'description' => 'A defect', 'color' => 'd73a4a', 'openCount' => 1, 'totalCount' => 2])
        ->and(collect($result['labels'])->firstWhere('id', $unused->id))->toMatchArray(['openCount' => 0, 'totalCount' => 0])
        ->and($result)->not->toHaveKey('truncated');
});

it('states how each kind is attached to a task or created', function (): void {
    $rules = app(DescribeTodoMetadata::class)->handle()['rules'];

    expect(array_keys($rules))->toBe(['areas', 'groups', 'priorities', 'labels'])
        ->and($rules['labels'])->toContain('lowercase')->toContain('labelNames')
        ->and($rules['groups'])->toContain('newGroupName');
});

it('searches names case-insensitively in every kind, and label descriptions too', function (): void {
    $f = metadataFixture();
    Label::factory()->create(['name' => 'needs research', 'description' => null]);
    $described = Label::factory()->create(['name' => 'agent task', 'description' => 'Work an agent can research alone']);
    Label::factory()->create(['name' => 'bug']);

    $research = app(DescribeTodoMetadata::class)->handle(query: 'RESEARCH');
    $dibs = app(DescribeTodoMetadata::class)->handle(query: 'dib');
    $work = app(DescribeTodoMetadata::class)->handle(query: 'work');

    expect(collect($research['labels'])->pluck('name')->all())->toBe(['agent task', 'needs research'])
        ->and(collect($research['labels'])->pluck('id')->all())->toContain($described->id)
        ->and($research['areas'])->toBe([])->and($research['groups'])->toBe([])
        ->and(collect($dibs['groups'])->pluck('id')->all())->toBe([$f['dibs']->id])->and($dibs['labels'])->toBe([])
        ->and(collect($work['areas'])->pluck('title')->all())->toBe(['Work'])
        ->and(collect($work['labels'])->pluck('name')->all())->toBe(['agent task']);
});

it('treats % and _ in the query as literal characters', function (): void {
    Label::factory()->create(['name' => '100% done']);
    Label::factory()->create(['name' => '1000 done']);
    Label::factory()->create(['name' => 'a_b']);
    Label::factory()->create(['name' => 'axb']);

    expect(collect(app(DescribeTodoMetadata::class)->handle(query: '100%')['labels'])->pluck('name')->all())->toBe(['100% done'])
        ->and(collect(app(DescribeTodoMetadata::class)->handle(query: 'a_b')['labels'])->pluck('name')->all())->toBe(['a_b']);
});

it('returns only the requested kinds, and ignores kinds it does not know', function (): void {
    metadataFixture();
    Label::factory()->create();

    $labelsOnly = app(DescribeTodoMetadata::class)->handle(kinds: ['labels']);
    $mixed = app(DescribeTodoMetadata::class)->handle(kinds: ['groups', 'bogus']);

    expect(array_keys($labelsOnly))->toBe(['labels', 'rules'])
        ->and(array_keys($mixed))->toBe(['groups', 'rules']);
});

it('scopes areas, groups and priorities to one area but leaves labels global', function (): void {
    $f = metadataFixture();
    Label::factory()->create(['name' => 'bug']);

    $result = app(DescribeTodoMetadata::class)->handle(area: $f['personal']->id);

    expect(collect($result['areas'])->pluck('id')->all())->toBe([$f['personal']->id])
        ->and(collect($result['groups'])->pluck('id')->all())->toBe([$f['dibs']->id])
        ->and(collect($result['priorities'])->pluck('areaId')->unique()->all())->toBe([$f['personal']->id])
        ->and(collect($result['labels'])->pluck('name')->all())->toBe(['bug']);
});

it('leaves out anything that is no longer available on GitHub', function (): void {
    $gone = GitHubProject::factory()->create(['title' => 'Gone', 'is_available' => false]);
    $field = ProjectField::factory()->for($gone, 'project')->create(['semantic_key' => 'group']);
    ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Ghost group']);
    Label::factory()->create(['name' => 'ghost label', 'is_available' => false]);
    $deadField = ProjectField::factory()->for(GitHubProject::factory()->create(), 'project')->create(['semantic_key' => 'group', 'is_available' => false]);
    ProjectFieldOption::factory()->for($deadField, 'field')->create(['name' => 'Ghost option']);

    $result = app(DescribeTodoMetadata::class)->handle();

    expect(collect($result['areas'])->pluck('title')->all())->not->toContain('Gone')
        ->and(collect($result['groups'])->pluck('name')->all())->not->toContain('Ghost group')->not->toContain('Ghost option')
        ->and(collect($result['labels'])->pluck('name')->all())->not->toContain('ghost label');
});

it('caps each kind and says which ones were cut short', function (): void {
    $repository = GitHubRepository::factory()->create();
    for ($i = 0; $i <= DescribeTodoMetadata::LIMIT; $i++) {
        Label::query()->create(['repository_id' => $repository->id, 'github_node_id' => 'L'.$i, 'name' => 'label '.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'is_available' => true]);
    }

    $result = app(DescribeTodoMetadata::class)->handle();

    expect($result['labels'])->toHaveCount(DescribeTodoMetadata::LIMIT)
        ->and($result['labels'][0]['name'])->toBe('label 0000')
        ->and($result['truncated'])->toBe(['labels']);
});

it('rejects an unknown area with an error naming it', function (): void {
    expect(fn () => app(DescribeTodoMetadata::class)->handle(area: 999_999))
        ->toThrow(ValidationException::class, 'No available area has id 999999.');
});

it('rejects an area that is no longer available', function (): void {
    $gone = GitHubProject::factory()->create(['is_available' => false]);

    expect(fn () => app(DescribeTodoMetadata::class)->handle(area: $gone->id))->toThrow(ValidationException::class);
});
