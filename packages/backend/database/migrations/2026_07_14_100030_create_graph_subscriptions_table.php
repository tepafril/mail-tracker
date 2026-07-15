<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Microsoft Graph change-notification subscriptions (Phase 2). The renewal cron
 * (`graph:renew-subscriptions`) scans this cross-tenant for rows expiring soon and
 * PATCHes them before the ~7-day cap. `client_state` is the shared secret echoed in
 * each notification and verified on receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graph_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subscription_id')->unique();
            $table->string('resource');
            $table->string('client_state');
            $table->timestamp('expiration')->index();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graph_subscriptions');
    }
};
