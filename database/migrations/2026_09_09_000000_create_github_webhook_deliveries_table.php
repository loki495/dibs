<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->string('event');
            $table->string('body_hash', 64);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_webhook_deliveries');
    }
};
