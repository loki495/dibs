<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * github_mutations predates the push-queue write model (2026-09-11): a synchronous-write
 * tracking table for the earlier GitHub-authoritative design. Nothing in the app has called
 * TrackGitHubMutation since the pivot; see docs/architecture.md. Dropping the table itself,
 * not just the dead PHP around it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('github_mutations');
    }

    public function down(): void
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
};
