<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table): void {
            $table->id();
            $table->string('github_node_id')->unique();
            $table->string('owner');
            $table->string('name');
            $table->string('full_name')->unique();
            $table->string('url');
            $table->boolean('is_private');
            $table->string('visibility')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('github_node_id')->unique();
            $table->string('owner');
            $table->unsignedInteger('github_number');
            $table->string('title');
            $table->string('url');
            $table->boolean('is_closed')->default(false);
            $table->boolean('is_public')->default(false);
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['owner', 'github_number']);
        });

        Schema::create('issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repository_id')->constrained('repositories')->cascadeOnDelete();
            $table->string('github_node_id')->unique();
            $table->unsignedInteger('github_number');
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('state');
            $table->string('state_reason')->nullable();
            $table->string('url')->nullable();
            $table->foreignId('parent_issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->string('github_parent_node_id')->nullable();
            $table->unsignedInteger('sibling_position')->default(0);
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'github_number']);
            $table->index(['parent_issue_id', 'sibling_position']);
        });

        Schema::create('project_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('github_node_id')->unique();
            $table->string('name');
            $table->string('data_type');
            $table->string('semantic_key')->nullable();
            $table->json('configuration_json')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('project_field_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_field_id')->constrained('project_fields')->cascadeOnDelete();
            $table->string('github_option_id');
            $table->string('name');
            $table->string('color')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['project_field_id', 'github_option_id']);
        });

        Schema::create('project_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('github_node_id')->unique();
            $table->foreignId('issue_id')->nullable()->constrained('issues')->cascadeOnDelete();
            $table->string('content_type');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('status_option_id')->nullable()->constrained('project_field_options')->nullOnDelete();
            $table->foreignId('group_option_id')->nullable()->constrained('project_field_options')->nullOnDelete();
            $table->date('planned_on')->nullable()->index();
            $table->date('due_on')->nullable()->index();
            $table->string('repeat_rule')->nullable();
            $table->json('raw_fields_json')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'issue_id']);
        });

        Schema::create('labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repository_id')->constrained('repositories')->cascadeOnDelete();
            $table->string('github_node_id')->unique();
            $table->string('name');
            $table->string('color')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'name']);
        });

        Schema::create('issue_label', function (Blueprint $table): void {
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('labels')->cascadeOnDelete();

            $table->unique(['issue_id', 'label_id']);
            $table->index('label_id');
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->string('github_node_id')->unique();
            $table->longText('body');
            $table->string('author_login')->nullable();
            $table->timestamp('remote_created_at')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_states', function (Blueprint $table): void {
            $table->id();
            $table->string('resource_key')->unique();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('cursor')->nullable();
            $table->text('watermark')->nullable();
            $table->string('etag')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('retry_after')->nullable();
            $table->unsignedBigInteger('completed_reconciliation_generation')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_states');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('issue_label');
        Schema::dropIfExists('labels');
        Schema::dropIfExists('project_items');
        Schema::dropIfExists('project_field_options');
        Schema::dropIfExists('project_fields');
        Schema::dropIfExists('issues');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('repositories');
    }
};
