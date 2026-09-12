<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('agent_name');
            $table->string('session_key')->unique();
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('task_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('agent_session_id')->constrained('agent_sessions')->cascadeOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['issue_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_claims');
        Schema::dropIfExists('agent_sessions');
    }
};
