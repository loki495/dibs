<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Models\IssueLink;
use App\Support\KnowledgeLabels;
use App\Support\TrustedAuthors;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The explicit links of an issue as capped one-line summaries: where it points to (`outgoing`) or what
 * points at it (`incoming`). The kind of a target is read from its labels. For agents, text from untrusted authors is
 * withheld; the web UI passes $forAgent = false and shows everything.
 */
class DescribeIssueLinks
{
    public const int MAX_LINKS = 50;

    public const int SUMMARY_LENGTH = 160;

    public function __construct(private readonly TrustedAuthors $trust) {}

    /** @return list<array<string, mixed>> */
    public function outgoing(Issue $issue, bool $forAgent = true): array
    {
        return $this->describe($issue->links()->with(['to.labels', 'comment']), 'to', $forAgent);
    }

    /** @return list<array<string, mixed>> */
    public function incoming(Issue $issue, bool $forAgent = true): array
    {
        return $this->describe($issue->linkedBy()->with(['from.labels']), 'from', $forAgent);
    }

    /**
     * @param  HasMany<IssueLink, Issue>  $query
     * @return list<array<string, mixed>>
     */
    private function describe($query, string $end, bool $forAgent): array
    {
        $links = $query->get()->filter(fn (IssueLink $link): bool => $link->{$end}?->is_available === true)->take(self::MAX_LINKS);

        return $links->map(function (IssueLink $link) use ($end, $forAgent): array {
            $target = $link->{$end};
            $names = $target->labels->where('is_available', true)->pluck('name')->all();
            $comment = $end === 'to' ? $link->comment : null;
            $source = $comment !== null ? ['author' => $comment->author_login, 'text' => $comment->body] : ['author' => $target->author_login, 'text' => $target->body];
            $allowed = ! $forAgent || $this->trust->allows($source['author']);

            return [
                'id' => $target->id,
                'number' => $target->github_number,
                'title' => $forAgent ? $this->trust->title($target) : $target->title,
                'withheld' => $forAgent && (! $this->trust->allows($target->author_login) || ! $allowed),
                'state' => $target->state,
                'labels' => $names,
                'knowledge' => count(array_intersect($names, KnowledgeLabels::NAMES)) > 0,
                'commentId' => $comment?->id,
                'summary' => $allowed ? $this->oneLine((string) $source['text']) : null,
            ];
        })->values()->all();
    }

    private function oneLine(string $text): string
    {
        return mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', strip_tags($text))), 0, self::SUMMARY_LENGTH, '…');
    }
}
