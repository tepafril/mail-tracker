<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit of every track attempt (MASTER-PLAN §4.9): tenant, message id,
 * outcome, and the SMOH HTTP status. Never updated or deleted — it is the compliance
 * record of what the system did with each email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_track_audits', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('user_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('internet_message_id')->nullable();
            $table->string('synthetic_key')->nullable();
            $table->string('outcome'); // e.g. logged | deduped | skipped_no_contact | failed
            $table->unsignedSmallInteger('smoh_status')->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_track_audits');
    }
};
