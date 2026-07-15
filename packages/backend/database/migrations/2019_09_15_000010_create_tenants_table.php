<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();

            // Per-tenant SMOH connection (MASTER-PLAN §4.1). Credentials are stored on
            // TEXT columns with Laravel `encrypted` casts (AES-256 via APP_KEY) — the
            // ciphertext is longer than the plaintext, so TEXT (not string) is required.
            $table->string('smoh_base_url')->nullable();
            $table->text('smoh_auth_username')->nullable();
            $table->text('smoh_auth_password')->nullable();
            $table->string('smoh_email_activity_set')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
