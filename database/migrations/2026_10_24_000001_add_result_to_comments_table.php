<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The structured completion evidence (commit, tests, docs, done_when_held) a closing comment carries.
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->json('result')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->dropColumn('result');
        });
    }
};
