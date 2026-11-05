<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_claims', function (Blueprint $table): void {
            // Why the claim ended: done, released, stale, dead, abandoned. Null on a live claim, on rows that predate this column, and on a claim that lapsed unreleased (idle, quota or crash cannot be recorded by the session itself).
            $table->string('end_reason', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_claims', function (Blueprint $table): void {
            $table->dropColumn('end_reason');
        });
    }
};
