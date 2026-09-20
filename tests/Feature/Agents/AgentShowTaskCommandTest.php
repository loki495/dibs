<?php

declare(strict_types=1);

use App\Models\Issue;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\InvalidArgumentException;

it('prints one task as json for a host-local agent', function (): void {
    $issue = Issue::factory()->create(['title' => 'Ship the thing']);

    $exitCode = Artisan::call('todo:agent:show', ['issue' => (string) $issue->id]);
    $output = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($output['issue']['id'])->toBe($issue->id);
});

it('rejects a non-numeric issue argument instead of crashing', function (): void {
    expect(fn () => Artisan::call('todo:agent:show', ['issue' => 'not-a-number']))
        ->toThrow(InvalidArgumentException::class, 'positive local Todo ID');
});

it('rejects a zero or negative issue argument instead of crashing', function (): void {
    expect(fn () => Artisan::call('todo:agent:show', ['issue' => '0']))
        ->toThrow(InvalidArgumentException::class, 'positive local Todo ID');
});
