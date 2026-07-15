<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users are federated identities, not password accounts. Each row is one mailbox user
 * of one tenant, keyed by the stable provider subject claim (Entra `oid` / Google `sub`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('provider'); // 'outlook' | 'gmail'

            // Stable per-provider subject. Exactly one is set per row.
            $table->string('entra_oid')->nullable(); // Entra user object id ('oid')
            $table->string('entra_tid')->nullable(); // Entra org tenant id ('tid')
            $table->string('google_sub')->nullable(); // Google subject ('sub')

            $table->string('email');
            $table->string('name')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');

            // A provider subject is globally unique. NULLs don't collide (a Gmail user
            // has entra_oid = NULL), so both indexes coexist cleanly.
            $table->unique(['provider', 'entra_oid']);
            $table->unique(['provider', 'google_sub']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
