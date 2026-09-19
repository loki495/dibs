<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_call_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id')->nullable();
            $table->string('tool');
            $table->json('arguments')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('agent_label')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('request_id');
            $table->index('created_at');
            $table->index('tool');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_call_logs');
    }
};
