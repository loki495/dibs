<?php

declare(strict_types=1);

use App\Support\LabelName;

it('normalizes a label name to lowercase with single spaces', function (string $input, string $expected): void {
    expect(LabelName::normalize($input))->toBe($expected);
})->with([
    'capitals' => ['Agent Task', 'agent task'],
    'edge whitespace' => ['  Needs   Research ', 'needs research'],
    'tabs and newlines between words' => ["bug\t\n ui", 'bug ui'],
    'non-ascii letters' => ['ÉTUDE', 'étude'],
    'already normalized' => ['feature request', 'feature request'],
    'blank stays blank' => ['   ', ''],
]);

it('is idempotent', function (): void {
    expect(LabelName::normalize(LabelName::normalize('  Mixed   CASE ')))->toBe('mixed case');
});
