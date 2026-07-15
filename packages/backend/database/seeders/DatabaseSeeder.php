<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MailProvider;
use App\Models\Tenant;
use App\Models\TenantIdentityMapping;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed one demo tenant with an Entra org mapping and a single Outlook user, so a
     * developer has something to exercise the API against locally. Values are taken
     * from env when present, otherwise sensible local defaults.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['id' => env('DEMO_TENANT_ID', 'demo-tenant')],
            [
                'smoh_base_url' => env('DEMO_SMOH_BASE_URL', 'https://demo.smoh.test'),
                'smoh_auth_username' => env('DEMO_SMOH_USERNAME', 'svc-demo'),
                'smoh_auth_password' => env('DEMO_SMOH_PASSWORD', 'change-me'),
                'is_active' => true,
            ],
        );

        TenantIdentityMapping::query()->firstOrCreate([
            'provider' => MailProvider::Outlook->value,
            'external_org_id' => env('DEMO_ENTRA_TID', '00000000-0000-0000-0000-000000000000'),
        ], ['tenant_id' => $tenant->id]);

        User::query()->firstOrCreate(
            ['provider' => MailProvider::Outlook->value, 'entra_oid' => env('DEMO_ENTRA_OID', (string) Str::uuid())],
            [
                'tenant_id' => $tenant->id,
                'entra_tid' => env('DEMO_ENTRA_TID', '00000000-0000-0000-0000-000000000000'),
                'email' => env('DEMO_USER_EMAIL', 'rep@demo.test'),
                'name' => 'Demo Rep',
            ],
        );
    }
}
