<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('labels', function (Blueprint $table): void {
            $table->string('github_node_id')->nullable()->change();
        });
        Schema::table('project_field_options', function (Blueprint $table): void {
            $table->string('github_option_id')->nullable()->change();
        });
        Schema::table('project_items', function (Blueprint $table): void {
            $table->string('github_node_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('labels', function (Blueprint $table): void {
            $table->string('github_node_id')->nullable(false)->change();
        });
        Schema::table('project_field_options', function (Blueprint $table): void {
            $table->string('github_option_id')->nullable(false)->change();
        });
        Schema::table('project_items', function (Blueprint $table): void {
            $table->string('github_node_id')->nullable(false)->change();
        });
    }
};
