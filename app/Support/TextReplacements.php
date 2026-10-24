<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\TodoValidationException;

/** Exact-string edits applied to the current text, so a small change (an atlas stamp) never resends a whole body. */
final class TextReplacements
{
    /** The same cap the MCP tools put on a whole body. */
    public const int MAX_LENGTH = 65535;

    /** @param  list<array{from: string, to: string, all?: bool}>  $replacements */
    public static function apply(string $text, array $replacements): string
    {
        foreach ($replacements as $index => $replacement) {
            $from = $replacement['from'];
            if ($from === '') {
                throw new TodoValidationException("replace[{$index}].from cannot be empty.");
            }
            $count = substr_count($text, $from);
            if ($count === 0) {
                throw new TodoValidationException("replace[{$index}].from was not found in the current text. Reread it and retry.");
            }
            if ($count > 1 && ! ($replacement['all'] ?? false)) {
                throw new TodoValidationException("replace[{$index}].from occurs {$count} times; make it unique or pass all: true.");
            }
            if (strlen($text) + $count * (strlen($replacement['to']) - strlen($from)) > self::MAX_LENGTH) {
                throw new TodoValidationException("replace[{$index}] would make the text longer than ".self::MAX_LENGTH.' characters.');
            }
            $text = str_replace($from, $replacement['to'], $text);
        }

        return $text;
    }
}
