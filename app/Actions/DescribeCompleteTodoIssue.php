<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class DescribeCompleteTodoIssue
{
    public function __construct(private readonly DescribeTodoIssue $details) {}

    /** @return array<string, mixed> */
    public function handle(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            $result = $this->details->handle($id, withComments: true, commentsPerPage: DescribeTodoIssue::MAX_COMMENTS_PER_PAGE);
            $comments = $result['comments'];
            $items = $comments['items'];
            for ($page = 2; $page <= $comments['lastPage']; $page++) {
                $next = $this->details->handle($id, withComments: true, commentsPage: $page, commentsPerPage: DescribeTodoIssue::MAX_COMMENTS_PER_PAGE);
                array_push($items, ...$next['comments']['items']);
            }
            $result['comments'] = [
                'items' => $items,
                'page' => 1,
                'perPage' => max(1, count($items)),
                'total' => count($items),
                'lastPage' => 1,
                'complete' => true,
            ];

            return $result;
        });
    }
}
