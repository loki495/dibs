<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_push_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('operation');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('payload');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_push_queue');
    }
};
