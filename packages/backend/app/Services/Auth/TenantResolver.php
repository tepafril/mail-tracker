<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\TenantIdentityMapping;
use App\Models\User;

/**
 * Turns a {@see VerifiedIdentity} into a tenant + a provisioned {@see User}
 * (MASTER-PLAN §4.2, §7.1). The org claim (Entra `tid` / Google `hd`) is looked up in
 * the central `tenant_identity_mappings` table; the user is then upserted under that
 * tenant's context.
 */
class TenantResolver
{
    /** Resolve the tenant for an identity, or null if the org isn't onboarded/active. */
    public function resolveTenant(VerifiedIdentity $identity): ?Tenant
    {
        if ($identity->orgId === null) {
            return null;
        }

        $mapping = TenantIdentityMapping::query()
            ->where('provider', $identity->provider->value)
            ->where('external_org_id', $identity->orgId)
            ->first();

        $tenant = $mapping?->tenant;

        return $tenant instanceof Tenant && $tenant->is_active ? $tenant : null;
    }

    /**
     * Find-or-create the user for this identity within the given tenant. Runs inside the
     * tenant context so the BelongsToTenant scope both finds the right row and stamps
     * `tenant_id` on insert.
     */
    public function provisionUser(Tenant $tenant, VerifiedIdentity $identity): User
    {
        $alreadyInitialized = tenancy()->initialized;
        tenancy()->initialize($tenant);

        try {
            if ($identity->provider->isMicrosoft()) {
                $user = User::query()
                    ->where('provider', $identity->provider->value)
                    ->where('entra_oid', $identity->subject)
                    ->first();

                // Reuse a mailbox enrolled without a sign-in (admin-managed tracked list:
                // entra_oid is null, matched by email) and backfill the stable oid, so a
                // first sign-in adopts the enrolled row instead of creating a duplicate.
                if ($user === null && $identity->email !== null) {
                    $user = User::query()
                        ->where('provider', $identity->provider->value)
                        ->whereNull('entra_oid')
                        ->where('email', $identity->email)
                        ->first();
                }

                $user ??= new User;
                $user->entra_oid = $identity->subject;
            } else {
                $user = User::query()->firstOrNew([
                    'provider' => $identity->provider->value,
                    'google_sub' => $identity->subject,
                ]);
            }

            $user->tenant_id = $tenant->id;
            $user->provider = $identity->provider;

            if ($identity->provider->isMicrosoft()) {
                $user->entra_tid = $identity->orgId;
            }
            if ($identity->email !== null) {
                $user->email = $identity->email;
            }
            if ($identity->name !== null) {
                $user->name = $identity->name;
            }
            $user->email ??= 'unknown@'.($identity->orgId ?? 'local');
            $user->last_seen_at = now();
            $user->save();

            return $user;
        } finally {
            if (! $alreadyInitialized) {
                tenancy()->end();
            }
        }
    }
}
