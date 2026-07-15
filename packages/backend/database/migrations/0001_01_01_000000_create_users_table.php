<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // NOTE: the `users` table is created in a later, tenant-aware migration
        // (…_create_users_table) because it carries a foreign key to `tenants`, which
        // is created at 2019_09_15_000010 — after this 0001-dated file. Our users are
        // federated identities (no password / reset tokens). Only `sessions` remains
        // here; it backs the web-session driver used by the Horizon dashboard.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
