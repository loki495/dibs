<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use Illuminate\Support\Facades\File;
use JsonException;

class ReadTodoBatchFile
{
    private const int MAX_BYTES = 2_097_152;

    /** @return list<mixed> */
    public function handle(string $path): array
    {
        if (! File::isFile($path) || ! File::isReadable($path)) {
            throw new TodoValidationException('Choose a readable batch JSON file.');
        }
        $contents = File::get($path);
        if (strlen($contents) > self::MAX_BYTES) {
            throw new TodoValidationException('The batch JSON file must be at most 2 MiB.');
        }
        try {
            $tasks = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TodoValidationException('The batch file must contain valid JSON.', 0, $exception);
        }
        if (! is_array($tasks) || ! array_is_list($tasks) || ! str_starts_with(ltrim($contents), '[')) {
            throw new TodoValidationException('The batch file must contain a JSON array of task definitions.');
        }

        return $tasks;
    }
}
