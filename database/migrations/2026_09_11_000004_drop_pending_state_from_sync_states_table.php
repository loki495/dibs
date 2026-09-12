<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_states', function (Blueprint $table): void {
            $table->dropColumn(['is_pending', 'pending_since']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_states', function (Blueprint $table): void {
            $table->boolean('is_pending')->default(false)->after('retry_after');
            $table->timestamp('pending_since')->nullable()->after('is_pending');
        });
    }
};
