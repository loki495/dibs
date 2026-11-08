<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Support\IssueFilters;
use App\Support\IssueSummary;
use App\Support\TrustedAuthors;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SearchTodoIssues
{
    public const MAX_QUERY_LENGTH = 200;

    public const EXCERPT_LENGTH = 240;

    private const int EXCERPT_CONTEXT = 60;

    /** bm25 column weights for issues_fts (title, body); a title hit counts ten times a body hit. */
    private const float TITLE_WEIGHT = 10.0;

    private const float BODY_WEIGHT = 1.0;

    public function __construct(private readonly ApplyIssueFilters $applyFilters, private readonly TrustedAuthors $trust) {}

    /** @return array<string, mixed> */
    public function handle(
        string $query = '',
        ?int $area = null,
        ?int $group = null,
        ?string $label = null,
        string $state = 'ALL',
        int $page = 1,
        int $perPage = ListTodoIssues::DEFAULT_PER_PAGE,
        ?IssueFilters $filters = null,
    ): array {
        $terms = array_values(array_unique(preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $filters = ($filters ?? new IssueFilters)->withLegacy($area, $group, $label, null);
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH || ($terms === [] && $filters->isEmpty())) {
            throw ValidationException::withMessages(['query' => 'Provide a non-empty search query of at most '.self::MAX_QUERY_LENGTH.' characters, or at least one filter.']);
        }

        $issues = Issue::query()->where('is_available', true)
            ->when($state !== 'ALL', fn ($builder) => $builder->where('state', $state))
            ->withCount(['children' => fn ($children) => $children->where('is_available', true)])
            ->with([
                'labels' => fn ($labels) => $labels->where('is_available', true),
                'projectItems' => fn ($items) => $items->where('is_available', true)
                    ->whereHas('project', fn ($project) => $project->where('is_available', true)),
                'projectItems.project', 'projectItems.groupOption', 'projectItems.priorityOption',
            ]);

        $unresolved = array_filter($this->applyFilters->handle($issues, $filters));

        $ranking = [];
        $patterns = [];
        $phrases = [];
        foreach ($terms as $term) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $ranking[] = "CASE WHEN title LIKE ? ESCAPE '\\' THEN 1 ELSE 0 END";
            $patterns[] = $pattern;

            // A term with letters or digits goes through the FTS5 indexes (stemmed, last word a prefix,
            // issue text or any comment); a term of pure punctuation can't be tokenized, so it stays a literal LIKE.
            preg_match_all('/[\p{L}\p{N}]+/u', $term, $words);
            if ($words[0] === []) {
                $issues->where(fn ($matches) => $matches
                    ->whereRaw("title LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("body LIKE ? ESCAPE '\\'", [$pattern]));

                continue;
            }

            $phrase = '"'.implode(' ', $words[0]).'"*';
            $phrases[] = $phrase;
            $issues->where(fn ($matches) => $matches
                ->whereRaw('issues.id IN (SELECT rowid FROM issues_fts WHERE issues_fts MATCH ?)', [$phrase])
                ->orWhereRaw('issues.id IN (SELECT issue_id FROM comments WHERE is_available = 1 AND id IN (SELECT rowid FROM comments_fts WHERE comments_fts MATCH ?))', [$phrase]));
        }

        if ($phrases !== []) {
            $scores = DB::table('issues_fts')
                ->selectRaw(sprintf('rowid AS rid, bm25(issues_fts, %F, %F) AS score', self::TITLE_WEIGHT, self::BODY_WEIGHT))
                ->whereRaw('issues_fts MATCH ?', [implode(' OR ', $phrases)]);
            $issues->select('issues.*')->leftJoinSub($scores, 'scores', 'scores.rid', '=', 'issues.id');
        }

        $paginator = $issues->when($ranking !== [], fn ($ranked) => $ranked->orderByRaw('('.implode(' + ', $ranking).') DESC', $patterns))
            ->when($phrases !== [], fn ($ranked) => $ranked->orderByRaw('scores.score IS NULL')->orderBy('scores.score'))
            ->orderBy('issues.id')
            ->paginate(max(1, min($perPage, ListTodoIssues::MAX_PER_PAGE)), ['*'], 'page', max(1, $page));

        return [
            'items' => $paginator->getCollection()->map(fn (Issue $issue): array => [
                ...IssueSummary::from($issue, $this->trust),
                'excerpt' => $this->trust->allows($issue->author_login) ? $this->excerpt($issue->body ?? '', $terms) : '',
            ])->all(),
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
            ...($unresolved === [] ? [] : ['unresolved' => $unresolved]),
        ];
    }

    /** @param list<string> $terms */
    private function excerpt(string $body, array $terms): string
    {
        $positions = [];
        foreach ($terms as $term) {
            $position = mb_stripos($body, $term);
            if ($position !== false) {
                $positions[] = $position;
            }
        }

        $start = $positions === [] ? 0 : max(0, min($positions) - self::EXCERPT_CONTEXT);
        $text = mb_substr($body, $start, self::EXCERPT_LENGTH);

        return ($start > 0 ? '…' : '').$text.($start + self::EXCERPT_LENGTH < mb_strlen($body) ? '…' : '');
    }
}
