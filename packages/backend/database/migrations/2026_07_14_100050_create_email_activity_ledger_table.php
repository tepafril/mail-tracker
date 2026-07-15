<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dedup ledger (MASTER-PLAN §4.8 / §7.2). One row per logical email per tenant.
 *
 * Two idempotency keys, both scoped to the tenant:
 *  - `internet_message_id`: the preferred key (RFC 5322 Message-ID, normalized). Present
 *    for received/synced mail on both providers. UNIQUE(tenant_id, internet_message_id).
 *  - `synthetic_key`: the send-time fallback used when no Message-ID exists yet (Outlook
 *    OnMessageSend). UNIQUE(tenant_id, synthetic_key). Reconciled to the real Message-ID
 *    when the sent item later surfaces via sync.
 *
 * NULLs never collide in a UNIQUE index (MySQL/SQLite/pgsql), so a received row
 * (synthetic_key = NULL) and a send-time row (internet_message_id = NULL) coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_activity_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('internet_message_id')->nullable();
            $table->string('synthetic_key')->nullable();

            $table->string('provider');   // 'outlook' | 'gmail'
            $table->string('direction');  // 'inbound' | 'outbound'
            $table->string('source')->default('client'); // 'client' | 'sync'

            $table->string('contact_id')->nullable();      // matched SMOH contact GUID
            $table->string('smoh_activity_id')->nullable(); // created CRM.Email id
            $table->string('subject')->nullable();

            // pending | matched | logged | skipped_no_contact | skipped_blacklisted | failed
            $table->string('status')->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('logged_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');

            $table->unique(['tenant_id', 'internet_message_id']);
            $table->unique(['tenant_id', 'synthetic_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_activity_ledger');
    }
};
