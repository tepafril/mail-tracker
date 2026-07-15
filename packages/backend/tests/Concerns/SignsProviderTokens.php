<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\MailProvider;
use App\Services\Auth\JwksProvider;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Test helper: a locally generated RSA keypair used both to SIGN provider tokens and
 * to back a fake {@see JwksProvider}. This lets tests exercise TokenVerifier's real
 * signature + iss/aud validation without touching Entra/Google.
 */
trait SignsProviderTokens
{
    private ?string $rsaPrivateKey = null;

    private ?string $rsaPublicKey = null;

    private string $testKid = 'test-kid-1';

    protected function ensureKeypair(): void
    {
        if ($this->rsaPrivateKey !== null) {
            return;
        }

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $privateKey);
        $this->rsaPrivateKey = $privateKey;
        $this->rsaPublicKey = openssl_pkey_get_details($res)['key'];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function signToken(array $claims): string
    {
        $this->ensureKeypair();

        return JWT::encode($claims, $this->rsaPrivateKey, 'RS256', $this->testKid);
    }

    /** A JwksProvider that returns our test public key for every provider. */
    protected function fakeJwksProvider(): JwksProvider
    {
        $this->ensureKeypair();
        $key = new Key($this->rsaPublicKey, 'RS256');
        $kid = $this->testKid;

        return new class($key, $kid) implements JwksProvider
        {
            public function __construct(private readonly Key $key, private readonly string $kid) {}

            public function keys(MailProvider $provider): array
            {
                return [$this->kid => $this->key];
            }
        };
    }
}
