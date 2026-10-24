<?php

declare(strict_types=1);

namespace App\Support;

/** The required sections of a plan step's body; a section is a line starting "<Name>:" (markdown emphasis, heading or list markers allowed). */
final class StepTemplate
{
    /** @var array<string, string> section name => line-start pattern */
    public const array REQUIRED = [
        'Done-when' => 'done[- ]when',
        'Files likely' => 'files likely',
        'Evidence' => 'evidence',
    ];

    /** @return list<string> the required sections the body lacks */
    public static function missing(?string $body): array
    {
        return array_keys(array_filter(
            self::REQUIRED,
            fn (string $pattern): bool => preg_match('/^[\s>#*_-]*'.$pattern.'[^:\n]*:[ \t]*\S/mi', (string) $body) !== 1,
        ));
    }
}
