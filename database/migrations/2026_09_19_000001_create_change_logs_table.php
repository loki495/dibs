<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id')->nullable();
            $table->string('source');
            $table->string('category');
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->text('summary');
            $table->json('changes')->nullable();
            $table->string('actor_type');
            $table->string('actor_label')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('request_id');
            $table->index('created_at');
            $table->index('action');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_logs');
    }
};
