<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\MailProvider;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * DEV/DEMO ONLY. Issues a first-party Sanctum token for a seeded demo user without any
 * real OIDC provider — so the thin clients can be exercised locally without an Entra or
 * Google app. Registered ONLY when `mail_tracker.dev_auth` is on (MAIL_TRACKER_DEV_AUTH=true),
 * which must never be enabled in production.
 */
class DevLoginController extends Controller
{
    private const ABILITIES = ['contacts:read', 'activities:write'];

    public function __invoke(): JsonResponse
    {
        abort_unless((bool) config('mail_tracker.dev_auth'), 404);

        $tenant = Tenant::query()->firstOrCreate(
            ['id' => 'demo-tenant'],
            [
                'smoh_base_url' => 'https://demo.smoh.test',
                'smoh_auth_username' => 'svc-demo',
                'smoh_auth_password' => 'demo',
                'is_active' => true,
            ],
        );

        // Provision the demo user under the tenant's context (BelongsToTenant scope).
        tenancy()->initialize($tenant);
        try {
            $user = User::query()->firstOrCreate(
                ['provider' => MailProvider::Outlook->value, 'entra_oid' => 'demo-oid'],
                [
                    'tenant_id' => $tenant->id,
                    'entra_tid' => 'demo-tid',
                    'email' => 'rep@demo.test',
                    'name' => 'Demo Rep',
                ],
            );
        } finally {
            tenancy()->end();
        }

        $token = $user->createToken('dev-login', self::ABILITIES);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => self::ABILITIES,
            'expires_in' => is_numeric($e = config('sanctum.expiration')) ? (int) $e * 60 : null,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'provider' => $user->provider->value,
                'tenant_id' => $user->tenant_id,
            ],
        ]);
    }
}
