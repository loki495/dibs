<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Issue;

/**
 * The sections of a plan step. A section is a step field (done_when, evidence, files_likely) or, when the
 * field is empty, a body line starting "<Name>:" (markdown emphasis, heading or list markers allowed).
 */
final class StepTemplate
{
    /** @var array<string, string> required section name => line-start pattern */
    public const array REQUIRED = [
        'Done-when' => 'done[- ]when',
        'Files likely' => 'files likely',
        'Evidence' => 'evidence',
    ];

    /** @var array<string, string> section name => the issues column that holds it as data */
    public const array COLUMNS = [
        'Done-when' => 'done_when',
        'Files likely' => 'files_likely',
        'Evidence' => 'evidence',
    ];

    /** @return list<string> the required sections neither a field nor the body supplies */
    public static function missing(?string $body, ?Issue $issue = null): array
    {
        return array_keys(array_filter(
            self::REQUIRED,
            fn (string $pattern, string $name): bool => self::filled($issue?->{self::COLUMNS[$name]}) === null && self::line($body, $pattern) === null,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Each section's field value, else its body line.
     *
     * @return array{objective: ?string, tier: ?string, doneWhen: ?string, evidence: ?string, filesLikely: ?string}
     */
    public static function values(Issue $issue, ?string $body): array
    {
        return [
            'objective' => self::line($body, 'objective'),
            'tier' => self::line($body, 'tier'),
            'doneWhen' => self::filled($issue->done_when) ?? self::line($body, self::REQUIRED['Done-when']),
            'evidence' => self::filled($issue->evidence) ?? self::line($body, self::REQUIRED['Evidence']),
            'filesLikely' => self::filled($issue->files_likely) ?? self::line($body, self::REQUIRED['Files likely']),
        ];
    }

    /** The text after "<Name>:" on the first matching body line, or null. */
    public static function line(?string $body, string $pattern): ?string
    {
        if (preg_match('/^[\s>#*_-]*'.$pattern.'[^:\n]*:[ \t]*(\S.*)$/mi', (string) $body, $match) !== 1) {
            return null;
        }

        return self::filled(ltrim($match[1], '*_ '));
    }

    private static function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
