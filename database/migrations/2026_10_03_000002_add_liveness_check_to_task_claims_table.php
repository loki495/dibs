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
            // Last result of dibs:claims:watch, which checks claims from the app container (host PIDs).
            // Display-only: claim takeover and heartbeat always re-check /proc directly.
            $table->boolean('liveness_alive')->nullable();
            $table->timestamp('liveness_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_claims', function (Blueprint $table): void {
            $table->dropColumn(['liveness_alive', 'liveness_checked_at']);
        });
    }
};
