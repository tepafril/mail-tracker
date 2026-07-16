<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEV/DEMO ONLY. Dummy CRM tables backing the in-backend mock SMOH service
 * (App\Http\Controllers\Dev\MockSmohController). They stand in for a real SMOH instance
 * so the CRM-depth features (Set Regarding, lead/account matching, …) can be built and
 * tested against the true OData wire protocol. NOT tenant-scoped — the mock is an
 * external system. See DYNAMICS-PARITY.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('primary_email')->nullable();
            $table->timestamps();
        });

        Schema::create('mock_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('email_business')->nullable()->index();
            $table->uuid('account_id')->nullable();
            $table->timestamps();
        });

        Schema::create('mock_leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('mock_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('regarding_id')->nullable()->index();
            $table->string('regarding_type')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('direction')->nullable();
            $table->string('sent_at')->nullable();
            $table->string('from_address')->nullable();
            $table->text('to_recipients')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_emails');
        Schema::dropIfExists('mock_leads');
        Schema::dropIfExists('mock_contacts');
        Schema::dropIfExists('mock_accounts');
    }
};
