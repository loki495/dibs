<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_write_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_write_receipts');
    }
};
