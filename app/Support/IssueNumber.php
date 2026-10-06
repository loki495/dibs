<?php

declare(strict_types=1);

namespace App\Support;

/** Local-only tasks have no GitHub number until they are mirrored, so "#N" must disappear rather than render as a bare "#". */
class IssueNumber
{
    public static function label(?int $number): string
    {
        return $number === null ? '' : '#'.$number;
    }

    /** "#N title", or just the title when there is no number. */
    public static function withTitle(?int $number, string $title): string
    {
        return trim(self::label($number).' '.$title);
    }
}
