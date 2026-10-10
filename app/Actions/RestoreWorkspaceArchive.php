<?php

declare(strict_types=1);

namespace App\Actions;

use App\Support\WorkspaceArchive;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestoreWorkspaceArchive
{
    public function __construct(private readonly ValidateWorkspaceArchive $validate) {}

    /** @return array<string, int> */
    public function handle(string $json, int $userId): array
    {
        $data = $this->validate->handle($json);
        try {
            return DB::transaction(function () use ($data, $userId): array {
                if (! WorkspaceArchive::isEmpty()) {
                    throw ValidationException::withMessages(['archive' => __('Import requires an empty workspace. No existing records were changed.')]);
                }
                foreach (WorkspaceArchive::TABLES as $table) {
                    foreach ($data[$table] as $row) {
                        if ($table === 'issues') {
                            $row['parent_issue_id'] = null;
                        }
                        if ($table === 'todo_capture_requests') {
                            $row['user_id'] = $userId;
                        }
                        DB::table($table)->insert($row);
                    }
                }
                foreach ($data['issues'] as $issue) {
                    if ($issue['parent_issue_id'] !== null) {
                        DB::table('issues')->where('id', $issue['id'])->update(['parent_issue_id' => $issue['parent_issue_id']]);
                    }
                }

                return array_map(count(...), $data);
            });
        } catch (QueryException $exception) {
            if (in_array(((int) ($exception->errorInfo[1] ?? 0)) & 0xFF, [5, 19], true)) {
                throw ValidationException::withMessages(['archive' => __('Import could not complete because of a data conflict. Nothing was imported; preview the archive again.')]);
            }
            throw $exception;
        }
    }
}
