<?php

declare(strict_types=1);

use App\Support\StepTemplate;

it('accepts a body with all sections in plain, bold or list form', function (string $body): void {
    expect(StepTemplate::missing($body))->toBe([]);
})->with([
    "Done-when: a\nFiles likely: b\nEvidence: c",
    "- **Done-when**: a\n## Files likely related: b\n* Evidence required: c",
]);

it('names each missing or empty section', function (?string $body, array $missing): void {
    expect(StepTemplate::missing($body))->toBe($missing);
})->with([
    [null, ['Done-when', 'Files likely', 'Evidence']],
    ["Done-when: a\nEvidence: c", ['Files likely']],
    ["Done-when:\nFiles likely: b\nEvidence: c", ['Done-when']],
    ["The done-when is a thing\nFiles likely: b\nEvidence: c", ['Done-when']],
]);
