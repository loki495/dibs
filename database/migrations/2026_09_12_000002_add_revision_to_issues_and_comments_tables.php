<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A dedicated counter, not updated_at: SQLite/Carbon's second-level timestamp
        // granularity lets two rapid revisions collide on the same value, silently
        // defeating an optimistic-concurrency check (#44).
        Schema::table('issues', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1)->after('body');
        });
        Schema::table('comments', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1)->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
        Schema::table('comments', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};
