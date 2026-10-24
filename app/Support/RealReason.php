<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Screens the free-text reasons a completion guard accepts instead of evidence ("none: <why>", a force
 * reason): a reason must say why in a few words, not just ask for the close.
 */
final class RealReason
{
    public const int MIN_WORDS = 5;

    /** Phrases that only ask for the close; a reason made of nothing else is refused. */
    private const array FILLER = [
        'force', 'forced', 'forcing', 'just close', 'close it', 'close', 'closing', 'done', 'n/a', 'na', 'none',
        'not needed', 'not applicable', 'no need', 'not required', 'skip', 'skipped', 'testing', 'test',
        'override', 'bypass', 'whatever', 'because', 'trust me', 'it is fine', 'its fine', 'fine', 'ok', 'okay',
        'please', 'just', 'to', 'the', 'it', 'this', 'task', 'guard', 'needed', 'need', 'no', 'not', 'is',
    ];

    /** Null when $reason reads as a real reason, else what is wrong with it. */
    public static function problem(?string $reason): ?string
    {
        $words = preg_split('/[^\pL\pN\/]+/u', mb_strtolower(trim((string) $reason)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < self::MIN_WORDS) {
            return 'give the actual reason in at least '.self::MIN_WORDS.' words';
        }
        if (array_diff($words, self::FILLER) === []) {
            return 'it only asks for the close; say why the evidence does not apply';
        }

        return null;
    }
}
