<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table): void {
            // Captured now, not yet enforced anywhere — a cheap-now hook for the deferred
            // multi-instance/multi-user question (#38), per #37's "Claim and liveness contract".
            $table->string('host_identifier')->nullable()->after('agent_name');
            $table->unsignedInteger('pid')->nullable()->after('host_identifier');
            // Read by the server from /proc/<pid>/stat at claim time — never trust a client-supplied value.
            $table->timestamp('process_started_at')->nullable()->after('pid');
            // SHA-256 of a random token; the plaintext is returned to the caller once, at claim time, and never stored.
            $table->string('capability_token_hash')->unique()->nullable()->after('process_started_at');
            // False when PID+start-time couldn't be verified (process not found, unreadable /proc, cross-namespace
            // caller) — the claim still succeeds but is flagged as weaker assurance per #37/#40's acceptance criteria.
            $table->boolean('is_verified_live')->default(false)->after('capability_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table): void {
            $table->dropColumn(['host_identifier', 'pid', 'process_started_at', 'capability_token_hash', 'is_verified_live']);
        });
    }
};
