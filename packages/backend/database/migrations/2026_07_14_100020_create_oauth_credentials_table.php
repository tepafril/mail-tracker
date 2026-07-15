<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user offline OAuth grants used by the Phase-2 zero-touch sync engine (the
 * add-in/add-on tokens are too short-lived to drive Graph subscriptions / Gmail watch).
 * Tokens are stored on TEXT columns with Laravel `encrypted` casts (see the model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // 'outlook' | 'gmail'
            $table->text('access_token')->nullable();  // encrypted
            $table->text('refresh_token')->nullable(); // encrypted
            $table->string('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_credentials');
    }
};
