<?php

declare(strict_types=1);

namespace App\Actions;

use App\Support\WorkspaceArchive;
use Illuminate\Support\Facades\DB;

class ExportWorkspaceArchive
{
    /** @return array{format: string, version: int, exported_at: string, data: array<string, list<array<string, mixed>>>} */
    public function handle(): array
    {
        return DB::transaction(function (): array {
            $data = [];
            foreach (WorkspaceArchive::TABLES as $table) {
                $query = DB::table($table);
                if ($table === 'issue_label') {
                    $query->orderBy('issue_id')->orderBy('label_id');
                } else {
                    $query->orderBy('id');
                }
                $data[$table] = $query->get()->map(fn (object $row): array => (array) $row)->all();
            }

            return ['format' => WorkspaceArchive::FORMAT, 'version' => WorkspaceArchive::VERSION, 'exported_at' => now()->toIso8601String(), 'data' => $data];
        });
    }
}
