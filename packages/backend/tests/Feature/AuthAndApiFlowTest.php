<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\MailProvider;
use App\Models\EmailActivityLedger;
use App\Models\Tenant;
use App\Models\TenantIdentityMapping;
use App\Models\User;
use App\Services\Auth\JwksProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsProviderTokens;
use Tests\TestCase;

/**
 * End-to-end Phase-1 happy path: a client exchanges an Entra token, then matches a
 * contact, logs an email (the job runs inline on the sync queue and writes to SMOH),
 * dedups a resubmission, and reads the timeline. SMOH is faked; the provider token is
 * really signed and really verified.
 */
class AuthAndApiFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsProviderTokens;

    private const BASE = 'https://tenant.smoh.test';

    private const AUDIENCE = 'api://app-id';

    private const TID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.entra.audience', self::AUDIENCE);
        config()->set('services.entra.issuer_template', 'https://login.microsoftonline.com/{tid}/v2.0');
        $this->app->instance(JwksProvider::class, $this->fakeJwksProvider());

        $tenant = Tenant::factory()->create([
            'id' => 'tenant-acme',
            'smoh_base_url' => self::BASE,
            'smoh_auth_username' => 'svc',
            'smoh_auth_password' => 'secret',
        ]);

        TenantIdentityMapping::create([
            'tenant_id' => $tenant->id,
            'provider' => MailProvider::Outlook->value,
            'external_org_id' => self::TID,
        ]);

        $this->fakeSmoh();
    }

    private function fakeSmoh(): void
    {
        $b64 = fn (array $x) => rtrim(strtr(base64_encode(json_encode($x)), '+/', '-_'), '=');
        $jwt = $b64(['alg' => 'none']).'.'.$b64(['exp' => time() + 3600]).'.s';

        $edmx = '<?xml version="1.0"?><edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx">'
            .'<edmx:DataServices><Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="CRM">'
            .'<EntityContainer Name="C"><EntitySet Name="Contacts" EntityType="CRM.Contact"/>'
            .'<EntitySet Name="Leads" EntityType="CRM.Lead"/><EntitySet Name="Accounts" EntityType="CRM.Account"/>'
            .'<EntitySet Name="Emails" EntityType="CRM.Email"/></EntityContainer></Schema></edmx:DataServices></edmx:Edmx>';

        Http::fake(function (Request $request) use ($jwt, $edmx) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['access_token' => $jwt], 200),
                str_contains($url, '/odata/$metadata') => Http::response($edmx, 200),
                str_contains($url, '/odata/Contacts') => Http::response(['value' => [['id' => '11111111-1111-1111-1111-111111111111', 'first_name' => 'Jane', 'email' => 'jane@client.com']]], 200),
                str_contains($url, '/odata/Leads') => Http::response(['value' => []], 200),
                str_contains($url, '/odata/Accounts') => Http::response(['value' => []], 200),
                str_contains($url, '/odata/Emails(') && $request->method() === 'PATCH' => Http::response(null, 204),
                str_contains($url, '/odata/Emails') && $request->method() === 'POST' => Http::response(null, 201, [
                    'OData-EntityId' => self::BASE.'/odata/Emails(activity-guid-9)',
                ]),
                str_contains($url, '/odata/Emails') => Http::response(['value' => [
                    ['id' => 'activity-guid-9', 'subject' => 'Deal update', 'direction' => 'outbound', 'sent_at' => '2026-07-14T10:00:00Z'],
                ]], 200),
                default => Http::response('unexpected '.$url, 500),
            };
        });
    }

    private function entraToken(): string
    {
        return $this->signToken([
            'aud' => self::AUDIENCE,
            'iss' => 'https://login.microsoftonline.com/'.self::TID.'/v2.0',
            'tid' => self::TID,
            'oid' => 'oid-rep-1',
            'preferred_username' => 'rep@acme.com',
            'name' => 'Acme Rep',
            'exp' => time() + 300,
        ]);
    }

    private function emailPayload(): array
    {
        return [
            'internetMessageId' => '<msg-100@acme.com>',
            'syntheticKey' => str_repeat('a', 64),
            'subject' => 'Deal update',
            'body' => '<p>Hi <script>evil()</script></p>',
            'bodyType' => 'html',
            'from' => ['address' => 'rep@acme.com', 'name' => 'Acme Rep'],
            'to' => [['address' => 'jane@client.com', 'name' => 'Jane']],
            'cc' => [],
            'sentAt' => '2026-07-14T10:00:00Z',
            'direction' => 'outbound',
            'provider' => 'outlook',
        ];
    }

    public function test_full_phase1_flow(): void
    {
        // 1. Exchange the provider token for a Sanctum token.
        $exchange = $this->postJson('/api/v1/auth/exchange', [
            'provider' => 'outlook',
            'token' => $this->entraToken(),
        ]);
        $exchange->assertOk()->assertJsonStructure(['token', 'abilities', 'user' => ['id', 'tenant_id']]);
        $token = $exchange->json('token');

        $this->assertDatabaseHas('users', ['entra_oid' => 'oid-rep-1', 'tenant_id' => 'tenant-acme', 'email' => 'rep@acme.com']);

        $auth = ['Authorization' => 'Bearer '.$token];

        // 2. /me works under tenancy.
        $this->getJson('/api/v1/me', $auth)->assertOk()->assertJson(['tenant_id' => 'tenant-acme']);

        // 3. Contact match.
        $this->postJson('/api/v1/contacts/match', ['email' => 'jane@client.com'], $auth)
            ->assertOk()->assertJson(['contactId' => '11111111-1111-1111-1111-111111111111']);

        // 4. Log an email — job runs inline (sync queue) and writes to SMOH.
        $log = $this->postJson('/api/v1/activities/email', $this->emailPayload(), $auth);
        $log->assertStatus(202)->assertJson(['deduped' => false]);

        $ledger = EmailActivityLedger::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(LedgerStatus::Logged, $ledger->status);
        $this->assertSame('activity-guid-9', $ledger->smoh_activity_id);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $ledger->contact_id);
        $this->assertSame('msg-100@acme.com', $ledger->internet_message_id); // normalized (no <>)

        // Full content is persisted: from, recipients, body (sanitized), sent time.
        $this->assertSame('rep@acme.com', $ledger->from_address);
        $this->assertSame('jane@client.com', $ledger->to_recipients[0]['address']);
        $this->assertSame('html', $ledger->body_type);
        $this->assertStringContainsString('Hi', (string) $ledger->body);
        $this->assertStringNotContainsString('<script>', (string) $ledger->body);
        $this->assertNotNull($ledger->email_sent_at);

        // ...and the body is encrypted at rest (raw column != decrypted value).
        $rawBody = \Illuminate\Support\Facades\DB::table('email_activity_ledger')->where('id', $ledger->id)->value('body');
        $this->assertNotSame($ledger->body, $rawBody);
        $this->assertStringNotContainsString('Hi', (string) $rawBody);

        // Audit + sanitized body (script stripped) sent to SMOH.
        $this->assertDatabaseHas('email_track_audits', ['outcome' => 'logged', 'tenant_id' => 'tenant-acme']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/odata/Emails')
            && $r->method() === 'POST'
            && ! str_contains((string) ($r['body'] ?? ''), '<script>'));

        // 5. Resubmit the same message -> deduped, no second activity.
        $this->postJson('/api/v1/activities/email', $this->emailPayload(), $auth)
            ->assertOk()->assertJson(['deduped' => true]);
        $this->assertSame(1, EmailActivityLedger::withoutGlobalScopes()->count());

        // 6. Timeline.
        $this->getJson('/api/v1/contacts/11111111-1111-1111-1111-111111111111/timeline', $auth)
            ->assertOk()->assertJsonCount(1, 'items');
    }

    public function test_exchange_rejects_unonboarded_org(): void
    {
        $token = $this->signToken([
            'aud' => self::AUDIENCE,
            'iss' => 'https://login.microsoftonline.com/99999999-0000-0000-0000-000000000000/v2.0',
            'tid' => '99999999-0000-0000-0000-000000000000',
            'oid' => 'oid-x',
            'exp' => time() + 300,
        ]);

        $this->postJson('/api/v1/auth/exchange', ['provider' => 'outlook', 'token' => $token])
            ->assertStatus(403);
    }

    public function test_protected_route_requires_auth(): void
    {
        $this->postJson('/api/v1/contacts/match', ['email' => 'x@y.com'])->assertUnauthorized();
    }

    public function test_user_provisioning_creates_exactly_one_user(): void
    {
        $payload = ['provider' => 'outlook', 'token' => $this->entraToken()];
        $this->postJson('/api/v1/auth/exchange', $payload)->assertOk();
        $this->postJson('/api/v1/auth/exchange', $payload)->assertOk();

        $this->assertSame(1, User::withoutGlobalScopes()->where('entra_oid', 'oid-rep-1')->count());
    }

    public function test_cc_only_send_is_logged(): void
    {
        $token = $this->postJson('/api/v1/auth/exchange', ['provider' => 'outlook', 'token' => $this->entraToken()])->json('token');
        $auth = ['Authorization' => 'Bearer '.$token];

        $payload = $this->emailPayload();
        $payload['to'] = [];
        $payload['cc'] = [['address' => 'jane@client.com', 'name' => 'Jane']];
        $payload['internetMessageId'] = '<cc-only-1@acme.com>';
        $payload['syntheticKey'] = str_repeat('b', 64);

        $this->postJson('/api/v1/activities/email', $payload, $auth)
            ->assertStatus(202)
            ->assertJson(['deduped' => false]);

        $ledger = EmailActivityLedger::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(LedgerStatus::Logged, $ledger->status);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $ledger->contact_id);
    }

    public function test_activities_requires_at_least_one_recipient(): void
    {
        $token = $this->postJson('/api/v1/auth/exchange', ['provider' => 'outlook', 'token' => $this->entraToken()])->json('token');

        $payload = $this->emailPayload();
        $payload['to'] = [];
        $payload['cc'] = [];

        $this->postJson('/api/v1/activities/email', $payload, ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422);
    }

    public function test_timeline_rejects_non_guid_contact(): void
    {
        $token = $this->postJson('/api/v1/auth/exchange', ['provider' => 'outlook', 'token' => $this->entraToken()])->json('token');

        $this->getJson('/api/v1/contacts/not-a-guid/timeline', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422);
    }

    public function test_record_search_returns_candidates(): void
    {
        $token = $this->postJson('/api/v1/auth/exchange', ['provider' => 'outlook', 'token' => $this->entraToken()])->json('token');

        $this->getJson('/api/v1/records?q=jane', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonStructure(['records' => [['id', 'type', 'label']]]);
    }

    public function test_set_regarding_updates_activity_and_ledger(): void
    {
        $token = $this->postJson('/api/v1/auth/exchange', ['provider' => 'outlook', 'token' => $this->entraToken()])->json('token');
        $auth = ['Authorization' => 'Bearer '.$token];

        // Log an email so there is an activity to re-point.
        $this->postJson('/api/v1/activities/email', $this->emailPayload(), $auth)->assertStatus(202);
        $ledger = EmailActivityLedger::withoutGlobalScopes()->firstOrFail();

        $this->postJson("/api/v1/activities/{$ledger->id}/regarding", [
            'regarding_id' => 'acct-123',
            'regarding_type' => 'CRM.Account',
        ], $auth)->assertOk()->assertJson(['regardingId' => 'acct-123', 'regardingType' => 'CRM.Account']);

        $ledger->refresh();
        $this->assertSame('acct-123', $ledger->contact_id);
        $this->assertSame('CRM.Account', $ledger->regarding_type);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/odata/Emails(activity-guid-9)'));
    }
}
