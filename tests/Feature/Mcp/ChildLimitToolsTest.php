<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\CreateTodoTask;
use App\Mcp\Tools\ReviseTodoTask;
use App\Mcp\Tools\ScaffoldTodoPlan;
use App\Models\GitHubRepository;
use App\Models\Issue;
use Illuminate\Testing\Fluent\AssertableJson;

require_once __DIR__.'/../../Support/ChildLimitFixtures.php';

// Intent: MCP write tools return a top-level `warnings` list of strings only when non-empty, and report
// a refusal as a tool error with nothing written.
beforeEach(function (): void {
    $this->repository = GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
    $this->parent = Issue::factory()->create(['repository_id' => $this->repository->id, 'title' => 'Crowded parent']);
});

it('omits warnings from todo_create when there are none', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'Quiet', 'parentId' => $this->parent->id])
        ->assertOk()->assertHasNoErrors()->assertDontSee('"warnings"');
});

it('includes a warnings list in todo_create at the 80th child', function (): void {
    insertChildren($this->parent, 79);

    TodoServer::tool(CreateTodoTask::class, ['title' => 'Eightieth', 'parentId' => $this->parent->id])
        ->assertOk()->assertHasNoErrors()
        ->assertStructuredContent(function (AssertableJson $json): void {
            $json->has('warnings', 1)->where('warnings.0', fn (string $message): bool => str_contains($message, 'Crowded parent'))->etc();
        });
});

it('returns an error from todo_create for the 101st child and writes nothing', function (): void {
    insertChildren($this->parent, 100);

    TodoServer::tool(CreateTodoTask::class, ['title' => 'Overflow', 'parentId' => $this->parent->id])
        ->assertHasErrors(['Crowded parent']);

    expect(Issue::query()->where('title', 'Overflow')->exists())->toBeFalse();
});

it('includes one top-level warnings entry for a todo_create batch', function (): void {
    insertChildren($this->parent, 78);

    TodoServer::tool(CreateTodoTask::class, ['tasks' => [
        ['title' => 'T1', 'parentId' => $this->parent->id],
        ['title' => 'T2', 'parentId' => $this->parent->id],
        ['title' => 'T3', 'parentId' => $this->parent->id],
    ]])->assertOk()->assertStructuredContent(function (AssertableJson $json): void {
        $json->where('succeeded', 3)->has('warnings', 1)->etc();
    });
});

it('omits warnings from a quiet todo_create batch', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['tasks' => [['title' => 'T1', 'parentId' => $this->parent->id]]])
        ->assertOk()->assertDontSee('"warnings"');
});

it('reports a refused batch item as an item error and still returns warnings for the others', function (): void {
    insertChildren($this->parent, 98);

    TodoServer::tool(CreateTodoTask::class, ['tasks' => [
        ['title' => 'Ninety-ninth', 'parentId' => $this->parent->id],
        ['title' => 'Hundredth', 'parentId' => $this->parent->id],
        ['title' => 'Hundred first', 'parentId' => $this->parent->id],
    ]])->assertOk()->assertStructuredContent(function (AssertableJson $json): void {
        $json->where('succeeded', 2)->where('failed', 1)->where('items.2.error.code', 'validation_error')->has('warnings', 1)->etc();
    });

    expect(childCount($this->parent))->toBe(100);
});

it('includes warnings in todo_revise when the new parent reaches 80 children', function (): void {
    insertChildren($this->parent, 79);
    $mover = Issue::factory()->create(['repository_id' => $this->repository->id, 'revision' => 1]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $mover->id, 'expectedRevision' => 1, 'parentId' => $this->parent->id])
        ->assertOk()->assertHasNoErrors()
        ->assertStructuredContent(function (AssertableJson $json): void {
            $json->has('warnings', 1)->etc();
        });
});

it('omits warnings from todo_revise when there are none', function (): void {
    $issue = Issue::factory()->create(['repository_id' => $this->repository->id, 'revision' => 1]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'title' => 'Renamed'])
        ->assertOk()->assertDontSee('"warnings"');
});

it('returns an error from todo_revise for a move into a full parent and changes nothing', function (): void {
    insertChildren($this->parent, 100);
    $mover = Issue::factory()->create(['repository_id' => $this->repository->id, 'revision' => 1, 'title' => 'Mover']);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $mover->id, 'expectedRevision' => 1, 'title' => 'Renamed', 'parentId' => $this->parent->id])
        ->assertHasErrors(['Crowded parent']);

    expect($mover->fresh()->parent_issue_id)->toBeNull()->and($mover->fresh()->title)->toBe('Mover');
});

it('includes warnings in todo_scaffold_plan for 80 children', function (): void {
    TodoServer::tool(ScaffoldTodoPlan::class, [
        'title' => 'Big plan',
        'children' => array_map(fn (int $i): array => ['title' => 'Step '.$i], range(1, 80)),
    ])->assertOk()->assertHasNoErrors()
        ->assertStructuredContent(function (AssertableJson $json): void {
            $json->has('warnings', 1)->etc();
        });
});

it('omits warnings from a small todo_scaffold_plan', function (): void {
    TodoServer::tool(ScaffoldTodoPlan::class, ['title' => 'Small plan', 'children' => [['title' => 'Only']]])
        ->assertOk()->assertDontSee('"warnings"');
});

it('refuses todo_scaffold_plan with 101 children and creates nothing', function (): void {
    TodoServer::tool(ScaffoldTodoPlan::class, [
        'title' => 'Too big',
        'children' => array_map(fn (int $i): array => ['title' => 'Step '.$i], range(1, 101)),
    ])->assertHasErrors(['Too big']);

    expect(Issue::query()->where('title', 'Too big')->exists())->toBeFalse();
});
