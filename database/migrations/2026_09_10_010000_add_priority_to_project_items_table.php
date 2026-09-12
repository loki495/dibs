<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_items', function (Blueprint $table): void {
            $table->foreignId('priority_option_id')->nullable()->after('group_option_id')->constrained('project_field_options')->nullOnDelete();
            $table->index('priority_option_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('priority_option_id');
        });
    }
};
