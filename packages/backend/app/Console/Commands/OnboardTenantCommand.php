<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MailProvider;
use App\Models\Tenant;
use App\Models\TenantIdentityMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Onboard a client organization: map a provider org identifier to a tenant so that
 * `auth/exchange` (and the Gmail add-on) can resolve real users to the right SMOH
 * instance (MASTER-PLAN §4.2, §7.1).
 *
 *   php artisan mail-tracker:onboard gmail acme.com --tenant=acme
 *   php artisan mail-tracker:onboard outlook <entra-tenant-guid> --tenant=acme \
 *       --smoh-url=https://acme.smoh.example --smoh-user=svc --smoh-pass=secret
 */
class OnboardTenantCommand extends Command
{
    protected $signature = 'mail-tracker:onboard
        {provider : outlook | gmail}
        {org : Entra tenant id (outlook) or Google Workspace domain (gmail)}
        {--tenant= : Tenant id to map to (created if missing; default derived from org)}
        {--smoh-url= : SMOH base URL for the tenant}
        {--smoh-user= : SMOH service username (stored encrypted)}
        {--smoh-pass= : SMOH service password (stored encrypted)}';

    protected $description = 'Map a provider org (Entra tid / Google Workspace domain) to a tenant.';

    public function handle(): int
    {
        $provider = MailProvider::tryFrom((string) $this->argument('provider'));
        if ($provider === null) {
            $this->error('Invalid provider; use "outlook" or "gmail".');

            return self::INVALID;
        }

        $org = (string) $this->argument('org');
        $tenantId = (string) ($this->option('tenant') ?: 'tenant-'.Str::slug($org, '-'));

        $tenant = Tenant::query()->firstOrCreate(['id' => $tenantId], ['is_active' => true]);

        // Optionally set/update the SMOH connection (encrypted at rest).
        $updates = array_filter([
            'smoh_base_url' => $this->option('smoh-url'),
            'smoh_auth_username' => $this->option('smoh-user'),
            'smoh_auth_password' => $this->option('smoh-pass'),
        ], fn ($v) => $v !== null);
        if ($updates !== []) {
            $tenant->forceFill($updates)->save();
        }

        $mapping = TenantIdentityMapping::query()->updateOrCreate(
            ['provider' => $provider->value, 'external_org_id' => $org],
            ['tenant_id' => $tenant->id],
        );

        $this->info(sprintf(
            'Onboarded: %s org "%s" -> tenant "%s"%s',
            $provider->value,
            $org,
            $tenant->id,
            $mapping->wasRecentlyCreated ? ' (new mapping)' : ' (updated)',
        ));

        if ($tenant->smoh_base_url === null && ! config('services.smoh.fake')) {
            $this->warn('No SMOH URL set for this tenant and SMOH_FAKE is off — CRM calls will fail until you set --smoh-url/user/pass.');
        }

        return self::SUCCESS;
    }
}
