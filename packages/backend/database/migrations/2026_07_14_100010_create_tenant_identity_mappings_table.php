<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant resolver (MASTER-PLAN §4.2 / §7.1). Maps a provider *organization*
 * identifier — Entra tenant id (`tid`) or Google Workspace domain (`hd`) — to one of
 * our tenants. During `auth/exchange` we read the org claim from the verified provider
 * token and look up the tenant here. This is a central table (NOT tenant-scoped): it is
 * how we discover which tenant a request belongs to before tenancy is initialized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_identity_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('provider'); // 'outlook' | 'gmail'
            // Entra `tid` for Outlook; Google Workspace domain (`hd`) for Gmail.
            $table->string('external_org_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['provider', 'external_org_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_identity_mappings');
    }
};
