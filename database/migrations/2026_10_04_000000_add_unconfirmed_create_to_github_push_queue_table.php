<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_push_queue', function (Blueprint $table): void {
            // A create whose last attempt failed ambiguously (timeout, 5xx) may have been applied on
            // GitHub anyway; the next attempt looks for it before creating again.
            $table->boolean('unconfirmed_create')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('github_push_queue', function (Blueprint $table): void {
            $table->dropColumn('unconfirmed_create');
        });
    }
};
