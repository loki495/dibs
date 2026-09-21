<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ChangeLog;
use App\Models\McpCallLog;
use App\Support\ActivityLog;
use App\Support\ActivityLogFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** One page of an activity log, newest first, narrowed by the given filters. */
class ListActivityLog
{
    public const string MCP_CALLS = ActivityLog::MCP_CALLS;

    public const string CHANGES = ActivityLog::CHANGES;

    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    /** Columns a free-text search looks in; the JSON ones are matched on their stored text. */
    private const array SEARCH_COLUMNS = [
        self::MCP_CALLS => ['tool', 'agent_label', 'error_message', 'arguments'],
        self::CHANGES => ['action', 'summary', 'subject_label', 'actor_label', 'changes'],
    ];

    /** @return LengthAwarePaginator<int, McpCallLog|ChangeLog> */
    public function handle(string $log, ActivityLogFilters $filters, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $query = ActivityLog::query($log);
        $isMcp = $log === self::MCP_CALLS;

        $this->applyDates($query, $filters);
        $this->applyEquals($query, $isMcp ? 'tool' : 'action', $filters->type);
        $this->applyEquals($query, 'status', $isMcp ? $filters->status : null);
        $this->applyEquals($query, 'category', $isMcp ? null : $filters->category);
        $this->applyEquals($query, 'source', $isMcp ? null : $filters->source);
        $this->applyEquals($query, 'request_id', $filters->requestId);
        $this->applySearch($query, $filters->search, self::SEARCH_COLUMNS[$log]);

        /** @var LengthAwarePaginator<int, McpCallLog|ChangeLog> */
        return $query->orderByDesc('id')->paginate(max(1, min($perPage, self::MAX_PER_PAGE)), ['*'], 'page', max(1, $page));
    }

    /** @param  Builder<McpCallLog>|Builder<ChangeLog>  $query */
    private function applyEquals(Builder $query, string $column, ?string $value): void
    {
        if (filled($value)) {
            $query->where($column, $value);
        }
    }

    /** @param  Builder<McpCallLog>|Builder<ChangeLog>  $query */
    private function applyDates(Builder $query, ActivityLogFilters $filters): void
    {
        $from = $this->boundary($filters->from, endOfDay: false);
        $to = $this->boundary($filters->to, endOfDay: true);

        if ($from instanceof CarbonImmutable) {
            $query->where('created_at', '>=', $from);
        }
        if ($to instanceof CarbonImmutable) {
            $query->where('created_at', '<=', $to);
        }
    }

    /** A day's first or last second in the configured timezone, as UTC; null for a blank or unparseable date. */
    private function boundary(?string $date, bool $endOfDay): ?CarbonImmutable
    {
        if (blank($date) || ! CarbonImmutable::canBeCreatedFromFormat($date, '!Y-m-d')) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, (string) config('dibs.timezone'));

        if ($parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return ($endOfDay ? $parsed->endOfDay() : $parsed->startOfDay())->utc();
    }

    /**
     * @param  Builder<McpCallLog>|Builder<ChangeLog>  $query
     * @param  list<string>  $columns
     */
    private function applySearch(Builder $query, ?string $term, array $columns): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $query->where(function (Builder $group) use ($columns, $like): void {
            foreach ($columns as $column) {
                $group->orWhereRaw($column." LIKE ? ESCAPE '\\'", [$like]);
            }
        });
    }
}
