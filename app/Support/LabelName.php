<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/** The one place that decides what a stored label name looks like: lowercase, single spaces, trimmed. */
final class LabelName
{
    public static function normalize(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }
}
