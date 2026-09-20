<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\Label;
use Illuminate\Support\Facades\Artisan;

it('prints open tasks as json for a host-local agent', function (): void {
    $issue = Issue::factory()->create(['title' => 'Ship the thing']);

    $exitCode = Artisan::call('todo:agent:list');
    $output = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and(collect($output['tasks'])->pluck('id'))->toContain($issue->id);
});

it('filters to knowledge records when --knowledge is passed', function (): void {
    $label = Label::factory()->create(['name' => 'research']);
    $knowledge = Issue::factory()->create(['title' => 'A finding']);
    $knowledge->labels()->attach($label);
    $task = Issue::factory()->create(['title' => 'An ordinary task']);

    Artisan::call('todo:agent:list', ['--knowledge' => true]);
    $output = json_decode(Artisan::output(), true);
    $ids = collect($output['tasks'])->pluck('id');

    expect($ids)->toContain($knowledge->id)
        ->and($ids)->not->toContain($task->id);
});
