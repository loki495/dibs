<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A plain ADD COLUMN, not ->change(): SQLite would rebuild `repositories` for a column change,
    // and issues/labels reference it with ON DELETE CASCADE.
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->boolean('is_local')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn('is_local');
        });
    }
};
