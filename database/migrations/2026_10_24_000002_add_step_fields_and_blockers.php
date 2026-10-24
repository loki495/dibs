<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Plan step fields as data (each falls back to the matching body line when empty), blocked-by edges
    // between sibling steps, and the rule revisions already sent to each agent identity in a work order.
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->text('done_when')->nullable();
            $table->text('evidence')->nullable();
            $table->text('files_likely')->nullable();
        });
        Schema::create('step_blockers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('blocked_by_issue_id')->constrained('issues')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['issue_id', 'blocked_by_issue_id']);
        });
        Schema::create('delivered_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('agent_name', 100);
            $table->foreignId('rule_issue_id')->constrained('issues')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->timestamps();
            $table->unique(['agent_name', 'rule_issue_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivered_rules');
        Schema::dropIfExists('step_blockers');
        Schema::table('issues', function (Blueprint $table): void {
            $table->dropColumn(['done_when', 'evidence', 'files_likely']);
        });
    }
};
