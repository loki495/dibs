<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

class PurgeTemporaryTask
{
    public function handle(Issue $issue, ?string $summary = null): void
    {
        if ($issue->children()->exists()) {
            throw new TodoValidationException('Consume all children before deleting a temporary task.');
        }

        $commentIds = DB::table('comments')->where('issue_id', $issue->id)->pluck('id');
        $sessionIds = DB::table('task_claims')->where('issue_id', $issue->id)->pluck('agent_session_id');
        DB::table('mcp_write_receipts')->where('subject_type', 'issue')->where('subject_id', $issue->id)->delete();
        DB::table('mcp_write_receipts')->where('subject_type', 'comment')->whereIn('subject_id', $commentIds)->delete();
        DB::table('change_logs')->where(function ($query) use ($issue, $commentIds): void {
            $query->where(fn ($q) => $q->where('subject_type', 'issue')->where('subject_id', $issue->id))
                ->orWhere(fn ($q) => $q->where('subject_type', 'comment')->whereIn('subject_id', $commentIds));
        })->delete();

        $incoming = DB::table('issue_links')->where('to_issue_id', $issue->id)->pluck('from_issue_id')->unique();
        foreach (Issue::query()->whereIn('id', $incoming)->where('is_temporary', false)->where('is_available', true)->get() as $source) {
            app(CreateTodoComment::class)->handle($source->id, "Temporary task {$issue->id} was consumed.");
        }
        $parent = $issue->parent_issue_id === null ? null : Issue::query()->find($issue->parent_issue_id);
        if ($parent instanceof Issue && ! $parent->is_temporary && $parent->is_available && trim((string) $summary) !== '') {
            app(CreateTodoComment::class)->handle($parent->id, trim((string) $summary));
        }
        $issue->delete();
        DB::table('agent_sessions')->whereIn('id', $sessionIds)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('task_claims')->whereColumn('task_claims.agent_session_id', 'agent_sessions.id'))
            ->delete();
    }
}
