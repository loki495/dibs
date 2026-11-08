<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * External-content FTS5 indexes over issues (title, body) and comments (body), kept current by
 * triggers so every writer (Actions, the GitHub import, the workspace restore) is covered without
 * application code. Availability and trust are not indexed: SearchTodoIssues filters on them.
 */
return new class extends Migration
{
    private const array INDEXES = [
        'issues' => ['fts' => 'issues_fts', 'columns' => ['title', 'body']],
        'comments' => ['fts' => 'comments_fts', 'columns' => ['body']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $index) {
            $fts = $index['fts'];
            $columns = implode(', ', $index['columns']);
            $new = implode(', ', array_map(fn (string $column): string => "new.{$column}", $index['columns']));
            $old = implode(', ', array_map(fn (string $column): string => "old.{$column}", $index['columns']));

            DB::unprepared("CREATE VIRTUAL TABLE {$fts} USING fts5({$columns}, content='{$table}', content_rowid='id', tokenize='porter unicode61')");
            DB::unprepared("CREATE TRIGGER {$fts}_ai AFTER INSERT ON {$table} BEGIN INSERT INTO {$fts}(rowid, {$columns}) VALUES (new.id, {$new}); END");
            DB::unprepared("CREATE TRIGGER {$fts}_ad AFTER DELETE ON {$table} BEGIN INSERT INTO {$fts}({$fts}, rowid, {$columns}) VALUES ('delete', old.id, {$old}); END");
            DB::unprepared("CREATE TRIGGER {$fts}_au AFTER UPDATE ON {$table} BEGIN INSERT INTO {$fts}({$fts}, rowid, {$columns}) VALUES ('delete', old.id, {$old}); INSERT INTO {$fts}(rowid, {$columns}) VALUES (new.id, {$new}); END");
            DB::unprepared("INSERT INTO {$fts}({$fts}) VALUES ('rebuild')");
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $index) {
            $fts = $index['fts'];
            foreach (['ai', 'ad', 'au'] as $suffix) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$fts}_{$suffix}");
            }
            DB::unprepared("DROP TABLE IF EXISTS {$fts}");
        }
    }
};
