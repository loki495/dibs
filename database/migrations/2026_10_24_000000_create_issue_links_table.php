<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_issue_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('to_issue_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('to_comment_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->timestamps();
            // NULLs are distinct in a unique index, so SyncIssueLinks also checks for an existing issue-level link.
            $table->unique(['from_issue_id', 'to_issue_id', 'to_comment_id']);
            $table->index('to_issue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_links');
    }
};
