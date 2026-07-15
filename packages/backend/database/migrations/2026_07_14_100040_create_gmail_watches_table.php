<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gmail `users.watch` registrations (Phase 2). `history_id` is the cursor for
 * `users.history.list`; `expiration` drives the daily `gmail:renew-watches` cron
 * (Gmail enforces a hard 7-day cap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_watches', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic');
            $table->unsignedBigInteger('history_id')->nullable();
            $table->timestamp('expiration')->index();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['user_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_watches');
    }
};
