<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\MailProvider;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Throwable;

/**
 * Verifies the OIDC token on a Gmail Cloud Pub/Sub push request (MASTER-PLAN §4.7).
 * The push subscription is configured with an OIDC token; Google signs it with the
 * same certs as its ID tokens. We require: valid signature, issuer = Google, audience =
 * this push endpoint URL, and the token's `email` = the configured push service account
 * (with `email_verified`).
 */
class GooglePushVerifier
{
    public function __construct(private readonly JwksProvider $jwks) {}

    public function verify(Request $request): void
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            throw new TokenVerificationException('Missing bearer token on Pub/Sub push.');
        }

        $token = substr($header, 7);

        JWT::$leeway = 60;

        try {
            /** @var array<string, mixed> $claims */
            $claims = (array) JWT::decode($token, $this->jwks->keys(MailProvider::Gmail));
        } catch (Throwable $e) {
            throw new TokenVerificationException('Pub/Sub token invalid: '.$e->getMessage(), 0, $e);
        }

        // Issuer must be Google (explicit allowlist, mirroring the main TokenVerifier).
        /** @var list<string> $issuers */
        $issuers = (array) config('services.google.issuers');
        if (! in_array((string) ($claims['iss'] ?? ''), $issuers, true)) {
            throw new TokenVerificationException('Pub/Sub token issuer not trusted.');
        }

        // Prefer an explicitly configured audience over the request URL (which can be
        // shaped by proxy Host/scheme headers if TrustProxies is misconfigured). The
        // config key exists but may be null, so fall back with ?: (not a config default).
        $expectedAud = (string) (config('services.google.pubsub_audience') ?: $request->url());
        if (($claims['aud'] ?? null) !== $expectedAud) {
            throw new TokenVerificationException('Pub/Sub token audience mismatch.');
        }

        $expectedEmail = (string) config('services.google.pubsub_service_account');
        if ($expectedEmail === '' || ($claims['email'] ?? null) !== $expectedEmail) {
            throw new TokenVerificationException('Pub/Sub token not from the configured service account.');
        }

        if (! filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw new TokenVerificationException('Pub/Sub token email not verified.');
        }
    }
}
