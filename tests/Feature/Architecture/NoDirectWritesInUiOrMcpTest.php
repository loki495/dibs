<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * Business logic lives in Actions (app/Actions), shared by the web UI, the MCP tools and the CLI.
 * The UI (Blade/Livewire views), the MCP tools and the HTTP layer must not write to the database
 * themselves; they call an Action. Keeping writes in one place is what lets every surface share the
 * same validation, revision checks and GitHub push-queue enqueueing.
 */

/** Layers that must delegate all writes to Actions, relative to the project root. */
function guardedLayers(): array
{
    return ['resources/views', 'app/Mcp', 'app/Http'];
}

/**
 * Lines that call an Eloquent/query-builder write or open a transaction.
 *
 * @return list<array{line: int, code: string}>
 */
function directWritesIn(string $source): array
{
    $write = '/(->|::)(update|save|saveQuietly|delete|forceDelete|restore|create|createMany|insert|upsert|updateOrCreate|firstOrCreate|sync|syncWithoutDetaching|attach|detach|toggle|increment|decrement|touch|truncate|destroy)\(|DB::(table|transaction|statement|insert|update|delete)/';

    $violations = [];
    foreach (preg_split('/\R/', $source) ?: [] as $index => $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#') || str_contains($trimmed, '{{--')) {
            continue;
        }
        if (preg_match($write, $line) === 1) {
            $violations[] = ['line' => $index + 1, 'code' => trim($line)];
        }
    }

    return $violations;
}

it('flags direct model and query-builder writes and transactions', function (string $code): void {
    expect(directWritesIn($code))->toHaveCount(1);
})->with([
    'update on a model' => '$issue->update([\'state\' => \'CLOSED\']);',
    'static create' => '$label = Label::create([\'name\' => $name]);',
    'relation sync' => '$issue->labels()->sync($ids);',
    'attach' => '$issue->labels()->attach($label);',
    'delete' => '$comment->delete();',
    'firstOrCreate' => 'Setting::firstOrCreate([]);',
    'transaction' => 'DB::transaction(function () { });',
    'raw query builder' => 'DB::table(\'issues\')->where(\'id\', 1)->update([]);',
    'save' => '$project->save();',
]);

it('does not flag calling an Action, reading data, or commented-out code', function (string $code): void {
    expect(directWritesIn($code))->toBe([]);
})->with([
    'Action call' => 'app(CloseTodoIssue::class)->handle($issue);',
    'read query' => '$issues = Issue::query()->where(\'is_available\', true)->with(\'labels\')->get();',
    'find' => '$issue = Issue::query()->find($this->selected);',
    'line comment' => '// $issue->update([\'state\' => \'CLOSED\']);',
    'docblock line' => ' * Calls $issue->update() for you.',
    'blade comment' => '{{-- $issue->update([]) --}}',
    'livewire reset' => '$this->reset(\'editError\');',
    'blank line' => '',
]);

it('reports the line number of each violation', function (): void {
    $source = "<?php\n\$a = 1;\n\$issue->update([]);\n\$b = 2;\n\$label->delete();\n";

    expect(directWritesIn($source))->toBe([
        ['line' => 3, 'code' => '$issue->update([]);'],
        ['line' => 5, 'code' => '$label->delete();'],
    ]);
});

it('scans real files in every guarded layer, so the check cannot pass by finding nothing', function (string $layer): void {
    expect(File::allFiles(base_path($layer)))->not->toBeEmpty();
})->with(guardedLayers());

it('keeps direct database writes out of the UI, MCP and HTTP layers', function (): void {
    $violations = [];
    foreach (guardedLayers() as $layer) {
        foreach (File::allFiles(base_path($layer)) as $file) {
            if (! in_array($file->getExtension(), ['php'], true)) {
                continue;
            }
            foreach (directWritesIn($file->getContents()) as $violation) {
                $violations[] = $layer.'/'.$file->getRelativePathname().':'.$violation['line'].'  '.$violation['code'];
            }
        }
    }

    $this->assertSame([], $violations, "Direct database writes found outside app/Actions. Move the logic into a typed Action and call it from here:\n".implode("\n", $violations));
});
