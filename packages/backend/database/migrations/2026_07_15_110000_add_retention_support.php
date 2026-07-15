<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention / GDPR-erasure support (MASTER-PLAN §7.4, §10):
 *  - ledger.content_purged_at: when the PII content of a row was scrubbed (kept for audit).
 *  - tenants.content_retention_days: per-tenant retention override; null falls back to the
 *    global config('mail_tracker.retention_days').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_activity_ledger', function (Blueprint $table) {
            $table->timestamp('content_purged_at')->nullable()->index();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('content_retention_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_activity_ledger', function (Blueprint $table) {
            $table->dropColumn('content_purged_at');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('content_retention_days');
        });
    }
};
