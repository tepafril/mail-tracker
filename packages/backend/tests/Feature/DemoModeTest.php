<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Models\EmailActivityLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Local demo mode: dev-login (no Entra) + in-process FakeSmohClient. Proves a developer
 * can exercise the whole flow with zero Azure/SMOH setup.
 */
class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // dev_auth is enabled via phpunit env (registers the route); turn on fake SMOH.
        config()->set('services.smoh.fake', true);
    }

    public function test_dev_login_then_match_log_timeline(): void
    {
        $login = $this->postJson('/api/v1/auth/dev-login')
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['tenant_id']]);
        $auth = ['Authorization' => 'Bearer '.$login->json('token')];

        // Contact match returns a stable, valid GUID for any address.
        $match = $this->postJson('/api/v1/contacts/match', ['email' => 'jane@client.com'], $auth)->assertOk();
        $contactId = $match->json('contactId');
        $this->assertTrue(Str::isUuid($contactId));

        // Log an outbound email to that contact (sync queue -> runs inline via fake SMOH).
        $this->postJson('/api/v1/activities/email', [
            'internetMessageId' => '<demo-1@acme.com>',
            'syntheticKey' => str_repeat('d', 64),
            'subject' => 'Demo email',
            'body' => 'hello',
            'bodyType' => 'text',
            'from' => ['address' => 'rep@demo.test'],
            'to' => [['address' => 'jane@client.com']],
            'sentAt' => '2026-07-15T10:00:00Z',
            'direction' => 'outbound',
            'provider' => 'outlook',
        ], $auth)->assertStatus(202)->assertJson(['deduped' => false]);

        $ledger = EmailActivityLedger::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(LedgerStatus::Logged, $ledger->status);
        $this->assertSame($contactId, $ledger->contact_id);

        // Timeline reflects the logged activity.
        $this->getJson("/api/v1/contacts/{$contactId}/timeline", $auth)
            ->assertOk()
            ->assertJsonCount(1, 'items');
    }

    public function test_dev_login_disabled_returns_404_when_flag_off(): void
    {
        // The route is registered (phpunit env), but the controller guards on the flag.
        config()->set('mail_tracker.dev_auth', false);

        $this->postJson('/api/v1/auth/dev-login')->assertNotFound();
    }
}
