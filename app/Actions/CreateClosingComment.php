<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;

/**
 * Creates the comment a close or completion carries: kind `closing`, the raw note as its body
 * (unadorned — the closing block is what distinguishes it, not a text prefix), and an optional list
 * of references (commit/PR links, free text) kept as structured data for the UI and todo_show to
 * render, rather than baked into the pushed GitHub body. A blank note with no references creates
 * nothing, the same as an ordinary completion with no summary today.
 */
class CreateClosingComment
{
    public function __construct(private readonly EnqueueGitHubPush $enqueue) {}

    /** @param  list<string>  $references */
    public function handle(int $issueId, ?string $note, array $references = []): ?Comment
    {
        $note = trim((string) $note);
        $references = array_values(array_filter(array_map(trim(...), $references), fn (string $reference): bool => $reference !== ''));

        // References alone, with no note, create nothing — the same "no summary, no comment"
        // rule an ordinary completion already follows; there is no body to show without a note.
        if ($note === '') {
            return null;
        }

        $comment = Comment::create([
            'issue_id' => $issueId,
            'github_node_id' => null,
            'body' => $note,
            'kind' => Comment::KIND_CLOSING,
            'references' => $references === [] ? null : $references,
            'revision' => 1,
            'is_available' => true,
            'last_seen_at' => now(),
        ]);

        $this->enqueue->handle('create_comment', 'comment', $comment->id, [], 'comment:create:'.$comment->id);

        return $comment;
    }
}
