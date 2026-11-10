<?php

declare(strict_types=1);

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Models\Comment;
use App\Models\Issue;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\InvalidArgumentException;

it('prints one task as json for a host-local agent', function (): void {
    $issue = Issue::factory()->create(['title' => 'Ship the thing']);

    $exitCode = Artisan::call('todo:agent:show', ['issue' => (string) $issue->id]);
    $output = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($output['id'])->toBe($issue->id)
        ->and($output['title'])->toBe('Ship the thing');
});

it('rejects a non-numeric issue argument instead of crashing', function (): void {
    expect(fn () => Artisan::call('todo:agent:show', ['issue' => 'not-a-number']))
        ->toThrow(InvalidArgumentException::class, 'positive local Todo ID');
});

it('rejects a zero or negative issue argument instead of crashing', function (): void {
    expect(fn () => Artisan::call('todo:agent:show', ['issue' => '0']))
        ->toThrow(InvalidArgumentException::class, 'positive local Todo ID');
});

it('keeps the default first page of comments', function (): void {
    $issue = Issue::factory()->create();
    $comments = Comment::factory()->for($issue, 'issue')->count(55)
        ->sequence(fn ($sequence) => ['author_login' => null, 'remote_created_at' => now()->addMinutes($sequence->index)])
        ->create();

    $exitCode = Artisan::call('todo:agent:show', ['issue' => (string) $issue->id]);
    $output = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($output['comments']['items'])->toHaveCount(50)
        ->and(array_column($output['comments']['items'], 'id'))->toBe($comments->take(50)->pluck('id')->all())
        ->and($output['comments']['total'])->toBe(55)
        ->and($output['comments']['page'])->toBe(1)
        ->and($output['comments']['lastPage'])->toBe(2);
});

it('returns every available comment in stable order and withholds untrusted text when opted in', function (): void {
    config(['dibs.trusted_github_authors' => '']);
    $issue = Issue::factory()->create();
    $comments = Comment::factory()->for($issue, 'issue')->count(107)
        ->sequence(fn ($sequence) => [
            'author_login' => $sequence->index === 102 ? 'untrusted-example' : null,
            'body' => 'Comment '.$sequence->index,
            'remote_created_at' => now()->addMinutes(intdiv($sequence->index, 2)),
        ])->create();
    Comment::factory()->for($issue, 'issue')->create(['is_available' => false]);

    $exitCode = Artisan::call('todo:agent:show', ['issue' => (string) $issue->id, '--all-comments' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($output['comments']['complete'])->toBeTrue()
        ->and($output['comments']['items'])->toHaveCount(107)
        ->and($output['comments']['total'])->toBe(count($output['comments']['items']))
        ->and($output['comments']['page'])->toBe(1)
        ->and($output['comments']['lastPage'])->toBe(1)
        ->and(array_column($output['comments']['items'], 'id'))->toBe($comments->pluck('id')->all())
        ->and($output['comments']['items'][102]['withheld'])->toBeTrue()
        ->and($output['comments']['items'][102]['body'])->not->toContain('Comment 102');
});

it('preserves missing and unavailable issue errors when all comments are requested', function (): void {
    $unavailable = Issue::factory()->create(['is_available' => false]);

    expect(fn () => Artisan::call('todo:agent:show', ['issue' => '999999', '--all-comments' => true]))
        ->toThrow(TodoRecordNotFoundException::class)
        ->and(fn () => Artisan::call('todo:agent:show', ['issue' => (string) $unavailable->id, '--all-comments' => true]))
        ->toThrow(TodoRecordUnavailableException::class);
});
