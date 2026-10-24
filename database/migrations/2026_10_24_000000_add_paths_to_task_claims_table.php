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
            // Absolute files/directories the claimant will touch, used only to report overlapping claims.
            $table->json('paths')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_claims', function (Blueprint $table): void {
            $table->dropColumn('paths');
        });
    }
};
