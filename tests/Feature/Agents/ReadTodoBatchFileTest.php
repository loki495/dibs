<?php

declare(strict_types=1);

use App\Actions\ReadTodoBatchFile;
use App\Exceptions\TodoValidationException;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->batchFile = tempnam(sys_get_temp_dir(), 'dibs-batch-input-');
});
afterEach(function (): void {
    if (is_file($this->batchFile)) {
        chmod($this->batchFile, 0600);
        unlink($this->batchFile);
    }
});

it('reads an independent task array from a JSON file', function (): void {
    $tasks = [['title' => 'File task', 'body' => 'Full body']];
    file_put_contents($this->batchFile, json_encode($tasks, JSON_THROW_ON_ERROR));
    expect(app(ReadTodoBatchFile::class)->handle($this->batchFile))->toBe($tasks);
});

it('reports missing unreadable malformed oversized and non-list files as validation failures', function (string $case, string $message): void {
    match ($case) {
        'missing' => unlink($this->batchFile),
        'unreadable' => File::partialMock()->shouldReceive('isReadable')->with($this->batchFile)->once()->andReturnFalse(),
        'malformed' => file_put_contents($this->batchFile, '{broken'),
        'oversized' => file_put_contents($this->batchFile, str_repeat(' ', 2_097_153)),
        'object' => file_put_contents($this->batchFile, '{"title":"not a list"}'),
        'scalar' => file_put_contents($this->batchFile, 'null'),
        'numeric object' => file_put_contents($this->batchFile, '{"0":{"title":"Not an array"}}'),
    };
    expect(fn () => app(ReadTodoBatchFile::class)->handle($this->batchFile))->toThrow(TodoValidationException::class, $message);
})->with([
    ['missing', 'readable'], ['unreadable', 'readable'], ['malformed', 'valid JSON'], ['oversized', '2 MiB'], ['object', 'JSON array'], ['scalar', 'JSON array'], ['numeric object', 'JSON array'],
]);
