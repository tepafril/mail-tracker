<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the full email content on the ledger so the backend is a complete record, not
 * just a dedup/audit index. PII fields (from, recipients, body) are stored on TEXT
 * columns and encrypted at rest via Laravel `encrypted` casts (MASTER-PLAN §7.4) — the
 * ciphertext is longer than the plaintext, so TEXT/LONGTEXT (not string) is required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_activity_ledger', function (Blueprint $table) {
            $table->text('from_address')->nullable();       // encrypted
            $table->text('to_recipients')->nullable();       // encrypted JSON [{address,name}]
            $table->text('cc_recipients')->nullable();       // encrypted JSON
            $table->text('bcc_recipients')->nullable();      // encrypted JSON
            $table->longText('body')->nullable();            // encrypted (sanitized)
            $table->string('body_type')->nullable();         // 'html' | 'text'
            $table->timestamp('email_sent_at')->nullable();  // the email's own send/receive time
        });
    }

    public function down(): void
    {
        Schema::table('email_activity_ledger', function (Blueprint $table) {
            $table->dropColumn([
                'from_address', 'to_recipients', 'cc_recipients', 'bcc_recipients',
                'body', 'body_type', 'email_sent_at',
            ]);
        });
    }
};
