<?php

declare(strict_types=1);

use App\Actions\CreateTodoIssue;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\CreateTodoTask;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

it('exposes the tool under the todo_create name', function (): void {
    expect(app(CreateTodoTask::class)->name())->toBe('todo_create');
});

it('declares nullable project and Group selectors for parent defaults', function (): void {
    $schema = app(CreateTodoTask::class)->schema(new JsonSchemaTypeFactory);
    $area = $schema['area']->toArray();
    $group = $schema['groupId']->toArray();
    expect($area['type'])->toContain('null')->and($group['type'])->toContain('null');
});

it('creates a task through the todo_create tool and returns full detail', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Ship the thing', 'body' => 'Some detail'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Ship the thing')
        ->assertSee('Some detail');

    expect(Issue::query()->where('title', 'Ship the thing')->exists())->toBeTrue()
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->exists())->toBeTrue();
});

it('returns a structured error for an unavailable area instead of crashing', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Task', 'area' => 999_999])
        ->assertHasErrors();

    expect(Issue::query()->count())->toBe(0);
});

it('rejects a call missing the required title', function (): void {
    TodoServer::tool(CreateTodoTask::class, [])
        ->assertHasErrors();
});

it('is idempotent through the tool: retrying the same key does not duplicate the task', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Once only', 'idempotencyKey' => 'agent-1'])->assertOk();
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Once only', 'idempotencyKey' => 'agent-1'])->assertOk();

    expect(Issue::query()->where('title', 'Once only')->count())->toBe(1);
});

it('attaches several labels by name, reusing existing ones case-insensitively and creating the rest', function (): void {
    $repository = GitHubRepository::query()->firstOrFail();
    $existing = Label::factory()->create(['repository_id' => $repository->id, 'name' => 'agent task']);

    TodoServer::tool(CreateTodoTask::class, ['title' => 'Labelled', 'labelNames' => ['Agent Task', 'bug', 'brand new']])
        ->assertOk()
        ->assertHasNoErrors();

    $issue = Issue::query()->where('title', 'Labelled')->firstOrFail();

    expect($issue->labels->pluck('name')->sort()->values()->all())->toBe(['agent task', 'brand new', 'bug'])
        ->and($issue->labels->pluck('id'))->toContain($existing->id)
        ->and(Label::query()->where('repository_id', $repository->id)->whereRaw('LOWER(name) = ?', ['agent task'])->count())->toBe(1);
});

it('combines labelNames with labelIds and newLabelName without duplicating a label', function (): void {
    $repository = GitHubRepository::query()->firstOrFail();
    $byId = Label::factory()->create(['repository_id' => $repository->id, 'name' => 'feature request']);

    TodoServer::tool(CreateTodoTask::class, [
        'title' => 'Mixed',
        'labelIds' => [$byId->id],
        'newLabelName' => 'ui',
        'labelNames' => ['Feature Request', 'UI', 'bug'],
    ])->assertOk()->assertHasNoErrors();

    $issue = Issue::query()->where('title', 'Mixed')->firstOrFail();

    expect($issue->labels->pluck('name')->sort()->values()->all())->toBe(['bug', 'feature request', 'ui']);
});

it('ignores blank label names and de-duplicates repeats within one call', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Repeats', 'labelNames' => ['bug', 'Bug', '  ', '']])
        ->assertOk()
        ->assertHasNoErrors();

    $issue = Issue::query()->where('title', 'Repeats')->firstOrFail();

    expect($issue->labels->pluck('name')->all())->toBe(['bug'])
        ->and(Label::query()->whereRaw('LOWER(name) = ?', ['bug'])->count())->toBe(1);
});

it('rejects labelNames that is not an array of short strings without creating anything', function (mixed $labelNames): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Bad labels', 'labelNames' => $labelNames])
        ->assertHasErrors();

    expect(Issue::query()->where('title', 'Bad labels')->exists())->toBeFalse()
        ->and(Label::query()->count())->toBe(0);
})->with([
    'a bare string' => ['bug'],
    'a non-string item' => [[['nested']]],
    'an over-long name' => [[str_repeat('x', 51)]],
]);

it('inherits project and Group through the tool when those fields are omitted or null', function (bool $explicitNull): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $parent = app(CreateTodoIssue::class)->handle(title: 'Parent', area: $project->id, groupId: $group->id);

    TodoServer::tool(CreateTodoTask::class, ['title' => 'Inherited tool child', 'parentId' => $parent->id, ...($explicitNull ? ['area' => null, 'groupId' => null] : [])])
        ->assertOk()->assertSee($project->title)->assertSee($group->name);

    expect(Issue::query()->where('title', 'Inherited tool child')->sole()->projectItems->sole()->group_option_id)->toBe($group->id);
})->with([false, true]);

it('returns a handled error for ambiguous parent projects without creating the child', function (): void {
    $parent = app(CreateTodoIssue::class)->handle(title: 'Ambiguous parent', area: GitHubProject::factory()->create()->id);
    ProjectItem::factory()->for($parent, 'issue')->create();

    TodoServer::tool(CreateTodoTask::class, ['title' => 'Ambiguous tool child', 'parentId' => $parent->id])
        ->assertHasErrors(['Choose an area explicitly'])->assertDontSee('internal server error');
    expect(Issue::query()->where('title', 'Ambiguous tool child')->exists())->toBeFalse();
});

it('creates a best-effort batch through the tool with ordered compact results', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['tasks' => [['title' => 'Batch A', 'idempotencyKey' => 'tool-batch-a'], ['title' => ''], ['title' => 'Batch B']]])
        ->assertOk()->assertStructuredContent(function (AssertableJson $json): void {
            $json->where('succeeded', 2)->where('failed', 1)->where('items.0.issue.title', 'Batch A')
                ->where('items.1.index', 1)->where('items.1.error.code', 'validation_error')
                ->where('items.2.issue.title', 'Batch B')->missing('items.0.issue.body')->etc();
        });
    expect(Issue::query()->count())->toBe(2);
});

it('rejects malformed batch envelopes or mixed single and batch inputs without writes', function (array $arguments, string $message): void {
    TodoServer::tool(CreateTodoTask::class, $arguments)->assertHasErrors([$message])->assertDontSee('internal server error');
    expect(Issue::query()->count())->toBe(0);
})->with([
    'empty' => [['tasks' => []], 'tasks'],
    'oversized' => [['tasks' => array_fill(0, 21, ['title' => 'Task'])], 'tasks'],
    'not list' => [['tasks' => ['one' => ['title' => 'Task']]], 'tasks'],
    'not array' => [['tasks' => 'bad'], 'tasks'],
    'mixed title' => [['title' => 'Single', 'tasks' => [['title' => 'Batch']]], 'either'],
    'mixed key' => [['idempotencyKey' => 'not-a-batch-key', 'tasks' => [['title' => 'Batch']]], 'either'],
]);

it('declares batch task fields while keeping single title conditional in the schema', function (): void {
    $schema = app(CreateTodoTask::class)->toArray()['inputSchema'];
    expect($schema['required'] ?? [])->not->toContain('title')
        ->and($schema['properties']['tasks']['maxItems'])->toBe(20)
        ->and($schema['properties']['tasks']['items']['required'])->toContain('title');
});
