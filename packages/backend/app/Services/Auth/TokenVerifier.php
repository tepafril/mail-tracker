<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\MailProvider;
use Firebase\JWT\JWT;
use Throwable;

/**
 * Validates the provider OIDC tokens the thin clients present at `auth/exchange`
 * (MASTER-PLAN §4.2, §5.1, §6.3), then extracts the identity we trust.
 *
 * firebase/php-jwt validates the signature (matching the token's `kid` against the
 * JWKS), `exp`, `nbf`, and `iat`. This class then enforces the OIDC claims that JWT
 * decoding does NOT check: `iss` and `aud`, plus the presence of the subject/org claims.
 * Getting `aud` wrong is the classic token-confusion bug, so it is mandatory here.
 */
class TokenVerifier
{
    /** Clock-skew tolerance (seconds) applied to exp/nbf/iat. */
    private const LEEWAY = 60;

    public function __construct(private readonly JwksProvider $jwks) {}

    public function verify(MailProvider $provider, string $token): VerifiedIdentity
    {
        $token = trim($token);
        if ($token === '') {
            throw new TokenVerificationException('Empty token.');
        }

        JWT::$leeway = self::LEEWAY;

        try {
            /** @var array<string, mixed> $claims */
            $claims = (array) JWT::decode($token, $this->jwks->keys($provider));
        } catch (TokenVerificationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TokenVerificationException('Token signature/claims invalid: '.$e->getMessage(), 0, $e);
        }

        return $provider->isMicrosoft()
            ? $this->verifyEntra($claims)
            : $this->verifyGoogle($claims);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function verifyEntra(array $claims): VerifiedIdentity
    {
        $audience = (string) config('services.entra.audience');
        $this->assertAudience($claims, $audience);

        $tid = $this->claim($claims, 'tid');
        $oid = $this->claim($claims, 'oid');
        if ($tid === null || $oid === null) {
            throw new TokenVerificationException('Entra token missing required oid/tid claims.');
        }

        // Issuer must be the v2.0 issuer for exactly this token's tenant.
        $template = (string) config('services.entra.issuer_template');
        $expectedIss = str_replace('{tid}', $tid, $template);
        $this->assertIssuer($claims, [$expectedIss]);

        // NOTE: Entra preferred_username/email/upn are unverified/mutable. We use this
        // for DISPLAY and audit only — it is NEVER an authorization key (authz is oid+tid,
        // and the tenant is resolved from tid), so a spoofed address cannot cross tenants.
        return new VerifiedIdentity(
            provider: MailProvider::Outlook,
            subject: $oid,
            orgId: $tid,
            email: $this->firstClaim($claims, ['preferred_username', 'email', 'upn']),
            name: $this->claim($claims, 'name'),
            claims: $claims,
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function verifyGoogle(array $claims): VerifiedIdentity
    {
        $clientId = (string) config('services.google.client_id');
        $this->assertAudience($claims, $clientId);

        /** @var list<string> $issuers */
        $issuers = (array) config('services.google.issuers');
        $this->assertIssuer($claims, $issuers);

        $sub = $this->claim($claims, 'sub');
        if ($sub === null) {
            throw new TokenVerificationException('Google token missing required sub claim.');
        }

        // Only trust the email if the provider says it is verified.
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        return new VerifiedIdentity(
            provider: MailProvider::Gmail,
            subject: $sub,
            orgId: $this->claim($claims, 'hd'), // Workspace domain; null for consumer accounts.
            email: $emailVerified ? $this->claim($claims, 'email') : null,
            name: $this->claim($claims, 'name'),
            claims: $claims,
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertAudience(array $claims, string $expected): void
    {
        if ($expected === '') {
            throw new TokenVerificationException('No expected audience is configured; refusing to accept token.');
        }

        $aud = $claims['aud'] ?? null;
        $auds = is_array($aud) ? array_map('strval', $aud) : [(string) $aud];

        if (! in_array($expected, $auds, true)) {
            throw new TokenVerificationException('Token audience mismatch.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  list<string>  $allowed
     */
    private function assertIssuer(array $claims, array $allowed): void
    {
        $iss = (string) ($claims['iss'] ?? '');
        if ($iss === '' || ! in_array($iss, $allowed, true)) {
            throw new TokenVerificationException('Token issuer not trusted.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function claim(array $claims, string $key): ?string
    {
        $value = $claims[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  list<string>  $keys
     */
    private function firstClaim(array $claims, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (($value = $this->claim($claims, $key)) !== null) {
                return $value;
            }
        }

        return null;
    }
}
