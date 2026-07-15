<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Initializes stancl tenancy from the *authenticated user's* tenant. This replaces
 * stancl's domain/subdomain identification middleware: our tenant is discovered from
 * the OIDC token at auth/exchange and pinned onto the Sanctum user, so once
 * `auth:sanctum` has run we simply initialize tenancy from `$user->tenant`.
 *
 * Must run AFTER `auth:sanctum`.
 */
class InitializeTenancyForUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $tenant = $user->tenant; // belongsTo relation from BelongsToTenant; not scoped

            if ($tenant instanceof Tenant && $tenant->is_active) {
                tenancy()->initialize($tenant);
            } else {
                abort(403, 'Your organization is not active.');
            }
        }

        return $next($request);
    }
}
