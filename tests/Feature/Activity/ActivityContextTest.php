<?php

declare(strict_types=1);

use App\Models\ChangeLog;
use App\Models\User;
use App\Services\Activity\ActivityContext;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function freshActivityContext(): ActivityContext
{
    app()->forgetScopedInstances();

    return app(ActivityContext::class);
}

it('defaults to an anonymous system context with a stable uuid request id', function (): void {
    $context = freshActivityContext();

    expect($context->source())->toBe(ChangeLog::SOURCE_SYSTEM)
        ->and($context->actorType())->toBe(ChangeLog::ACTOR_SYSTEM)
        ->and($context->actorLabel())->toBeNull()
        ->and(Str::isUuid($context->requestId()))->toBeTrue()
        ->and($context->requestId())->toBe($context->requestId());
});

it('is one instance within a scope and a fresh one after the scope is flushed', function (): void {
    $first = freshActivityContext();
    $first->beginMcp('claude-code');

    expect(app(ActivityContext::class))->toBe($first);

    app()->forgetScopedInstances();
    $second = app(ActivityContext::class);

    expect($second)->not->toBe($first)
        ->and($second->source())->toBe(ChangeLog::SOURCE_SYSTEM)
        ->and($second->requestId())->not->toBe($first->requestId());
});

it('resolves the signed-in user as the actor of a web context', function (): void {
    $this->actingAs(User::factory()->create(['name' => 'Andres']));

    $context = freshActivityContext();
    $context->beginWeb();

    expect($context->source())->toBe(ChangeLog::SOURCE_UI)
        ->and($context->actorType())->toBe(ChangeLog::ACTOR_USER)
        ->and($context->actorLabel())->toBe('Andres');
});

it('treats a web context with nobody signed in as an anonymous system actor', function (): void {
    $context = freshActivityContext();
    $context->beginWeb();

    expect($context->source())->toBe(ChangeLog::SOURCE_UI)
        ->and($context->actorType())->toBe(ChangeLog::ACTOR_SYSTEM)
        ->and($context->actorLabel())->toBeNull();
});

it('picks up a user who signs in after the web context began', function (): void {
    $context = freshActivityContext();
    $context->beginWeb();
    $this->actingAs(User::factory()->create(['name' => 'Andres']));

    expect($context->actorType())->toBe(ChangeLog::ACTOR_USER)
        ->and($context->actorLabel())->toBe('Andres');
});

it('resolves an MCP call as an agent, even when a user session happens to exist', function (): void {
    $this->actingAs(User::factory()->create());

    $context = freshActivityContext();
    $context->beginMcp('claude-code');

    expect($context->source())->toBe(ChangeLog::SOURCE_MCP)
        ->and($context->actorType())->toBe(ChangeLog::ACTOR_AGENT)
        ->and($context->actorLabel())->toBe('claude-code');
});

it('records an MCP call with an unknown agent as an unlabeled agent', function (): void {
    $context = freshActivityContext();
    $context->beginMcp(null);

    expect($context->actorType())->toBe(ChangeLog::ACTOR_AGENT)
        ->and($context->actorLabel())->toBeNull();
});

it('resolves a console command as a cli source with the command as its label', function (): void {
    $context = freshActivityContext();
    $context->beginConsole('todo:push:drain');

    expect($context->source())->toBe(ChangeLog::SOURCE_CLI)
        ->and($context->actorType())->toBe(ChangeLog::ACTOR_SYSTEM)
        ->and($context->actorLabel())->toBe('todo:push:drain');
});

it('resolves a scheduler task as a system source labeled with the task', function (): void {
    $context = freshActivityContext();
    $context->beginSystem('demo-cleanup');

    expect($context->source())->toBe(ChangeLog::SOURCE_SYSTEM)
        ->and($context->actorType())->toBe(ChangeLog::ACTOR_SYSTEM)
        ->and($context->actorLabel())->toBe('demo-cleanup');
});

it('gives every new operation its own request id and drops the previous actor', function (): void {
    $context = freshActivityContext();

    $context->beginMcp('claude-code');
    $first = $context->requestId();

    $context->beginMcp('opencode');

    expect($context->requestId())->not->toBe($first)
        ->and($context->actorLabel())->toBe('opencode');

    $context->beginWeb();

    expect($context->source())->toBe(ChangeLog::SOURCE_UI)
        ->and($context->actorType())->toBe(ChangeLog::ACTOR_SYSTEM)
        ->and($context->actorLabel())->toBeNull();
});

it('reports whether an operation has begun', function (): void {
    $context = freshActivityContext();

    expect($context->hasBegun())->toBeFalse();

    $context->beginConsole('inspire');

    expect($context->hasBegun())->toBeTrue();
});

it('begins a web context per HTTP request with a request id that is not shared between requests', function (): void {
    Route::middleware('web')->get('/_activity-probe', fn () => response()->json([
        'source' => app(ActivityContext::class)->source(),
        'actorType' => app(ActivityContext::class)->actorType(),
        'actorLabel' => app(ActivityContext::class)->actorLabel(),
        'requestId' => app(ActivityContext::class)->requestId(),
    ]));

    $this->actingAs(User::factory()->create(['name' => 'Andres']));

    $first = $this->getJson('/_activity-probe')->assertOk()->json();
    $second = $this->getJson('/_activity-probe')->assertOk()->json();

    expect($first['source'])->toBe(ChangeLog::SOURCE_UI)
        ->and($first['actorType'])->toBe(ChangeLog::ACTOR_USER)
        ->and($first['actorLabel'])->toBe('Andres')
        ->and($second['requestId'])->not->toBe($first['requestId']);
});

it('handles an unauthenticated web request without failing and without inventing a user', function (): void {
    Route::middleware('web')->get('/_activity-probe', fn () => response()->json([
        'actorType' => app(ActivityContext::class)->actorType(),
        'actorLabel' => app(ActivityContext::class)->actorLabel(),
    ]));

    $this->getJson('/_activity-probe')
        ->assertOk()
        ->assertExactJson(['actorType' => ChangeLog::ACTOR_SYSTEM, 'actorLabel' => null]);
});

it('begins a cli context when an artisan command starts', function (): void {
    $context = freshActivityContext();

    Event::dispatch(new CommandStarting('todo:push:drain', new ArrayInput([]), new NullOutput));

    expect($context->source())->toBe(ChangeLog::SOURCE_CLI)
        ->and($context->actorLabel())->toBe('todo:push:drain');
});

it('labels an artisan invocation with an empty command name', function (): void {
    $context = freshActivityContext();

    Event::dispatch(new CommandStarting('', new ArrayInput([]), new NullOutput));

    expect($context->source())->toBe(ChangeLog::SOURCE_CLI)
        ->and($context->actorLabel())->toBe('artisan');
});

it('keeps the outer command context when a nested command starts', function (): void {
    $context = freshActivityContext();

    Event::dispatch(new CommandStarting('demo:build', new ArrayInput([]), new NullOutput));
    $requestId = $context->requestId();
    Event::dispatch(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput));

    expect($context->actorLabel())->toBe('demo:build')
        ->and($context->requestId())->toBe($requestId);
});

it('begins a system context when a scheduled task starts', function (): void {
    $context = freshActivityContext();
    $task = new ScheduledEvent(new CacheEventMutex(app('cache')), 'true');
    $task->name('demo-cleanup');

    Event::dispatch(new ScheduledTaskStarting($task));

    expect($context->source())->toBe(ChangeLog::SOURCE_SYSTEM)
        ->and($context->actorLabel())->toBe('demo-cleanup');
});
