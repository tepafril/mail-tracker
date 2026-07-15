<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailProvider;
use App\Services\Auth\TokenVerificationException;
use App\Services\Auth\TokenVerifier;
use Tests\Concerns\SignsProviderTokens;
use Tests\TestCase;

class TokenVerifierTest extends TestCase
{
    use SignsProviderTokens;

    private const AUDIENCE = 'api://11111111-2222-3333-4444-555555555555';

    private const TID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.entra.audience', self::AUDIENCE);
        config()->set('services.entra.issuer_template', 'https://login.microsoftonline.com/{tid}/v2.0');
        config()->set('services.google.client_id', 'google-client-id.apps.googleusercontent.com');
        config()->set('services.google.issuers', ['https://accounts.google.com']);
    }

    private function verifier(): TokenVerifier
    {
        return new TokenVerifier($this->fakeJwksProvider());
    }

    private function entraClaims(array $overrides = []): array
    {
        return array_merge([
            'aud' => self::AUDIENCE,
            'iss' => 'https://login.microsoftonline.com/'.self::TID.'/v2.0',
            'tid' => self::TID,
            'oid' => 'user-oid-123',
            'preferred_username' => 'rep@acme.com',
            'name' => 'Acme Rep',
            'exp' => time() + 300,
            'iat' => time(),
        ], $overrides);
    }

    public function test_verifies_valid_entra_token(): void
    {
        $identity = $this->verifier()->verify(MailProvider::Outlook, $this->signToken($this->entraClaims()));

        $this->assertSame(MailProvider::Outlook, $identity->provider);
        $this->assertSame('user-oid-123', $identity->subject);
        $this->assertSame(self::TID, $identity->orgId);
        $this->assertSame('rep@acme.com', $identity->email);
    }

    public function test_rejects_wrong_audience(): void
    {
        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verify(MailProvider::Outlook, $this->signToken($this->entraClaims(['aud' => 'api://someone-else'])));
    }

    public function test_rejects_issuer_tenant_mismatch(): void
    {
        $this->expectException(TokenVerificationException::class);
        // iss for a *different* tenant than the tid claim -> not trusted.
        $this->verifier()->verify(MailProvider::Outlook, $this->signToken($this->entraClaims([
            'iss' => 'https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/v2.0',
        ])));
    }

    public function test_rejects_expired_token(): void
    {
        $this->expectException(TokenVerificationException::class);
        $this->verifier()->verify(MailProvider::Outlook, $this->signToken($this->entraClaims([
            'exp' => time() - 3600,
            'iat' => time() - 7200,
        ])));
    }

    public function test_verifies_google_token_and_honors_email_verified(): void
    {
        $base = [
            'aud' => 'google-client-id.apps.googleusercontent.com',
            'iss' => 'https://accounts.google.com',
            'sub' => 'google-sub-999',
            'hd' => 'acme.com',
            'email' => 'rep@acme.com',
            'name' => 'Acme Rep',
            'exp' => time() + 300,
        ];

        $verified = $this->verifier()->verify(MailProvider::Gmail, $this->signToken($base + ['email_verified' => true]));
        $this->assertSame('google-sub-999', $verified->subject);
        $this->assertSame('acme.com', $verified->orgId);
        $this->assertSame('rep@acme.com', $verified->email);

        // Unverified email is dropped.
        $unverified = $this->verifier()->verify(MailProvider::Gmail, $this->signToken($base + ['email_verified' => false]));
        $this->assertNull($unverified->email);
    }
}
