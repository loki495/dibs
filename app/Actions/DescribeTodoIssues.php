<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoValidationException;
use App\Support\BatchIssueDetail;

class DescribeTodoIssues
{
    public const MAX_IDS = 20;

    public const DEFAULT_RESPONSE_CHARS = 60_000;

    public const MIN_RESPONSE_CHARS = 20_000;

    public const MAX_RESPONSE_CHARS = 120_000;

    private const int JSON_ENVELOPE_RESERVE = 100;

    public function __construct(private readonly DescribeTodoIssue $describe, private readonly BatchIssueDetail $budget) {}

    /** @param list<int> $ids
     * @return array{items: list<array<string, mixed>>}
     */
    public function handle(array $ids, bool $withComments = false, int $commentsPage = 1, int $commentsPerPage = DescribeTodoIssue::DEFAULT_COMMENTS_PER_PAGE, ?int $maxBodyLength = null, int $maxResponseChars = self::DEFAULT_RESPONSE_CHARS): array
    {
        if ($ids === [] || count($ids) > self::MAX_IDS) {
            throw new TodoValidationException('Choose between 1 and '.self::MAX_IDS.' issue ids.');
        }
        if ($maxResponseChars < self::MIN_RESPONSE_CHARS || $maxResponseChars > self::MAX_RESPONSE_CHARS) {
            throw new TodoValidationException('Choose a response budget between '.self::MIN_RESPONSE_CHARS.' and '.self::MAX_RESPONSE_CHARS.' characters.');
        }
        // Reserve the JSON envelope and separators before sharing the remaining budget equally.
        $perItem = intdiv($maxResponseChars - self::JSON_ENVELOPE_RESERVE, count($ids));
        $items = [];
        $cache = [];
        foreach ($ids as $id) {
            if (isset($cache[$id])) {
                $items[] = $cache[$id];

                continue;
            }
            try {
                $detail = $this->describe->handle($id, $withComments, $commentsPage, $commentsPerPage, $maxBodyLength);
                $item = $this->budget->fit($detail, $perItem);
            } catch (TodoRecordNotFoundException) {
                $item = ['id' => $id, 'error' => ['code' => 'not_found', 'message' => 'No Todo issue with this local id.']];
            } catch (TodoRecordUnavailableException) {
                $item = ['id' => $id, 'error' => ['code' => 'unavailable', 'message' => 'This Todo issue is no longer available.']];
            }
            $cache[$id] = $item;
            $items[] = $item;
        }

        return ['items' => $items];
    }
}
