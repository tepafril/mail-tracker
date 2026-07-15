<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\MailProvider;
use Firebase\JWT\JWK;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Default {@see JwksProvider}: fetches each provider's JWKS over HTTPS and caches the
 * parsed keys (MASTER-PLAN §4.2 calls for a Redis-cached JWKS). Signing keys rotate
 * infrequently, so a short TTL keeps verification fast while still picking up rotation.
 */
final class HttpJwksProvider implements JwksProvider
{
    public function keys(MailProvider $provider): array
    {
        $uri = $this->jwksUri($provider);
        $ttl = (int) config('services.jwks_cache_ttl', 3600);

        $jwks = Cache::remember("jwks:{$provider->value}", $ttl, function () use ($uri): array {
            $response = Http::acceptJson()->get($uri);
            if (! $response->successful()) {
                throw new TokenVerificationException("Failed to fetch JWKS from {$uri} (HTTP {$response->status()}).");
            }

            /** @var array<string, mixed> $json */
            $json = $response->json();

            return $json;
        });

        // Default alg RS256 for JWKs that omit `alg` (both Entra and Google use RS256).
        return JWK::parseKeySet($jwks, 'RS256');
    }

    private function jwksUri(MailProvider $provider): string
    {
        $uri = $provider->isMicrosoft()
            ? config('services.entra.jwks_uri')
            : config('services.google.jwks_uri');

        if (! is_string($uri) || $uri === '') {
            throw new TokenVerificationException("No JWKS URI configured for provider [{$provider->value}].");
        }

        return $uri;
    }
}
