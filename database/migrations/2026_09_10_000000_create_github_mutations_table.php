<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_mutations', function (Blueprint $table): void {
            $table->id();
            $table->string('kind');
            $table->foreignId('issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->string('status')->index();
            $table->json('intent_json');
            $table->text('error')->nullable();
            $table->boolean('requires_reconciliation')->default(false)->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_mutations');
    }
};
