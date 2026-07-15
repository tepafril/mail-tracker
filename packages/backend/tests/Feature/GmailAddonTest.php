<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailProvider;
use App\Models\Tenant;
use App\Models\TenantIdentityMapping;
use App\Services\Auth\JwksProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsProviderTokens;
use Tests\TestCase;

class GmailAddonTest extends TestCase
{
    use RefreshDatabase;
    use SignsProviderTokens;

    private const CLIENT_ID = 'gclient.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google.client_id', self::CLIENT_ID);
        config()->set('services.google.issuers', ['https://accounts.google.com']);
        $this->app->instance(JwksProvider::class, $this->fakeJwksProvider());
    }

    private function userIdToken(string $hd = 'acme.com'): string
    {
        return $this->signToken([
            'aud' => self::CLIENT_ID,
            'iss' => 'https://accounts.google.com',
            'sub' => 'g-sub-1',
            'hd' => $hd,
            'email' => 'rep@acme.com',
            'email_verified' => true,
            'exp' => time() + 300,
        ]);
    }

    public function test_contextual_returns_log_card_for_onboarded_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        TenantIdentityMapping::create([
            'tenant_id' => $tenant->id,
            'provider' => MailProvider::Gmail->value,
            'external_org_id' => 'acme.com',
        ]);

        $response = $this->postJson('/api/gmail/addon/contextual', [
            'authorizationEventObject' => ['userIdToken' => $this->userIdToken()],
            'gmail' => ['messageId' => 'msg-abc'],
        ]);

        $response->assertOk()
            ->assertJsonPath('renderActions.action.navigations.0.pushCard.header.title', 'Log to SMOH CRM')
            ->assertSee('rep@acme.com');

        $this->assertDatabaseHas('users', ['google_sub' => 'g-sub-1', 'tenant_id' => $tenant->id]);
    }

    public function test_contextual_shows_not_onboarded_card_for_unknown_org(): void
    {
        $this->postJson('/api/gmail/addon/contextual', [
            'authorizationEventObject' => ['userIdToken' => $this->userIdToken('unknown.com')],
            'gmail' => ['messageId' => 'msg-abc'],
        ])->assertOk()->assertSee('not onboarded');
    }

    public function test_contextual_requires_user_id_token(): void
    {
        $this->postJson('/api/gmail/addon/contextual', ['gmail' => ['messageId' => 'x']])
            ->assertStatus(401);
    }
}
