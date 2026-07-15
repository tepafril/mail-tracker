<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeTokenRequest;
use App\Services\Auth\TenantResolver;
use App\Services\Auth\TokenVerificationException;
use App\Services\Auth\TokenVerifier;
use Illuminate\Http\JsonResponse;

/**
 * Exchanges a verified provider OIDC token for a first-party Sanctum token
 * (MASTER-PLAN §4.2, §4.5). No SMOH credential is ever involved here — the client
 * proves who it is with the provider token; the backend maps that to a tenant and
 * issues its own scoped token.
 */
class AuthExchangeController extends Controller
{
    /** Abilities granted to the client token (least privilege for the thin clients). */
    private const ABILITIES = ['contacts:read', 'activities:write'];

    public function __invoke(
        ExchangeTokenRequest $request,
        TokenVerifier $verifier,
        TenantResolver $resolver,
    ): JsonResponse {
        try {
            $identity = $verifier->verify($request->provider(), $request->token());
        } catch (TokenVerificationException $e) {
            return response()->json(['message' => 'Invalid token: '.$e->getMessage()], 401);
        }

        $tenant = $resolver->resolveTenant($identity);
        if ($tenant === null) {
            return response()->json([
                'message' => 'Your organization is not onboarded for mail tracking.',
            ], 403);
        }

        $user = $resolver->provisionUser($tenant, $identity);

        $token = $user->createToken('mail-tracker-client', self::ABILITIES);

        $expiration = config('sanctum.expiration');

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => self::ABILITIES,
            'expires_in' => is_numeric($expiration) ? (int) $expiration * 60 : null,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'provider' => $user->provider->value,
                'tenant_id' => $user->tenant_id,
            ],
        ]);
    }
}
