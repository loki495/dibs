<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ListTodoTasks;
use App\Models\Issue;
use App\Models\Label;

it('exposes the tool under the todo_list name', function (): void {
    expect(app(ListTodoTasks::class)->name())->toBe('todo_list');
});

it('lists tasks through the todo_list tool with default view and state', function (): void {
    Issue::factory()->create(['title' => 'Visible task']);
    $knowledge = Issue::factory()->create();
    $knowledge->labels()->attach(Label::factory()->create(['name' => 'decision']));

    TodoServer::tool(ListTodoTasks::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Visible task')
        ->assertDontSee($knowledge->title);
});

it('respects the view argument when called through the tool', function (): void {
    Issue::factory()->create();
    $lesson = Issue::factory()->create(['title' => 'A learned lesson']);
    $lesson->labels()->attach(Label::factory()->create(['name' => 'lesson']));

    TodoServer::tool(ListTodoTasks::class, ['view' => 'knowledge'])
        ->assertOk()
        ->assertSee('A learned lesson');
});

it('rejects an invalid view value before calling the handler', function (): void {
    TodoServer::tool(ListTodoTasks::class, ['view' => 'not-a-real-view'])
        ->assertHasErrors();
});
