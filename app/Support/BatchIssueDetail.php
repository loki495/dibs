<?php

declare(strict_types=1);

namespace App\Support;

final class BatchIssueDetail
{
    private const int MIN_TEXT_LENGTH = 16;

    /** @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    public function fit(array $detail, int $maxChars): array
    {
        $full = $this->item($detail, [], []);
        if ($this->size($full) <= $maxChars) {
            return $full;
        }

        $omitted = [];
        $arrays = array_filter($detail, is_array(...));
        uasort($arrays, fn (array $a, array $b): int => $this->size($b) <=> $this->size($a));
        $candidate = $this->clipped($detail, self::MIN_TEXT_LENGTH, $omitted);
        foreach (array_keys($arrays) as $key) {
            if ($this->size($candidate) <= $maxChars) {
                break;
            }
            unset($detail[$key]);
            $omitted[] = $key;
            $candidate = $this->clipped($detail, self::MIN_TEXT_LENGTH, $omitted);
        }

        if ($this->size($candidate) > $maxChars) {
            $core = array_intersect_key($detail, array_flip(['id', 'revision', 'state', 'withheld']));

            return $this->item($core, [], [...$omitted, ...array_keys(array_diff_key($detail, $core))]);
        }

        $low = self::MIN_TEXT_LENGTH;
        $high = $maxChars;
        while ($low <= $high) {
            $limit = intdiv($low + $high, 2);
            $next = $this->clipped($detail, $limit, $omitted);
            if ($this->size($next) <= $maxChars) {
                $candidate = $next;
                $low = $limit + 1;
            } else {
                $high = $limit - 1;
            }
        }

        return $candidate;
    }

    /** @param array<string, mixed> $detail
     * @param  list<string>  $omitted
     * @return array<string, mixed>
     */
    private function clipped(array $detail, int $limit, array $omitted): array
    {
        $truncated = [];
        $originalBody = $detail['body'] ?? null;
        foreach ($detail as $key => $value) {
            $clipped = $this->clip($value, $limit);
            if ($clipped !== $value) {
                $truncated[] = $key;
                $detail[$key] = $clipped;
            }
        }
        if (($detail['body'] ?? null) !== $originalBody) {
            $detail['bodyTruncated'] = true;
        }

        return $this->item($detail, $truncated, $omitted);
    }

    private function clip(mixed $value, int $limit): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $child): mixed => $this->clip($child, $limit), $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $detail
     * @param  list<string>  $truncated
     * @param  list<string>  $omitted
     * @return array<string, mixed>
     */
    private function item(array $detail, array $truncated, array $omitted): array
    {
        return ['id' => $detail['id'], 'issue' => $detail, 'truncatedFields' => $truncated, 'omittedFields' => $omitted];
    }

    /** @param array<array-key, mixed> $value */
    private function size(array $value): int
    {
        return strlen(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
