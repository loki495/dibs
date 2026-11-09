<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Non-fatal notices that actions raise during one request or command. The caller flushes them into
 * its response. A keyed warning replaces an earlier one with the same key, so a batch reports each
 * subject once, with the latest message.
 */
class WriteWarnings
{
    /** @var array<int|string, string> */
    private array $warnings = [];

    public function add(string $message, ?string $key = null): void
    {
        if ($key === null) {
            $this->warnings[] = $message;
        } else {
            $this->warnings[$key] = $message;
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_values($this->warnings);
    }

    /**
     * Flush into a result array as a top-level `warnings` list, only when there is something to report.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function attachTo(array $result): array
    {
        $list = $this->flush();

        return $list === [] ? $result : $result + ['warnings' => $list];
    }

    /** @return list<string> */
    public function flush(): array
    {
        $all = $this->all();
        $this->warnings = [];

        return $all;
    }
}
