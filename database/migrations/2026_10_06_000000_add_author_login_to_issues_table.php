<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Null means the issue was created through Dibs; the next GitHub import fills in imported ones.
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->string('author_login')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->dropColumn('author_login');
        });
    }
};
