<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The OData type of the record an activity is "regarding" (CRM.Contact / CRM.Lead /
 * CRM.Account). Paired with the existing contact_id, which now holds the matched record's
 * id regardless of its type (lead/account matching — DYNAMICS-PARITY.md Phase C).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_activity_ledger', function (Blueprint $table) {
            $table->string('regarding_type')->nullable()->after('contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('email_activity_ledger', function (Blueprint $table) {
            $table->dropColumn('regarding_type');
        });
    }
};
