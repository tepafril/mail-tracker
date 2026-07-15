<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataObjects\EmailMessageData;
use App\Enums\EmailDirection;
use App\Enums\LedgerStatus;
use App\Enums\MailProvider;
use App\Jobs\ParseGraphNotificationJob;
use App\Models\EmailActivityLedger;
use App\Models\GraphSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Email\EmailIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 (Microsoft Graph zero-touch sync), exercised against the in-process fakes.
 */
class GraphSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.graph.fake', true); // canned Graph messages, no network
        config()->set('services.smoh.fake', true);  // in-process CRM so logging succeeds
    }

    private function userWithSubscription(array $tenantAttrs = []): array
    {
        $tenant = Tenant::factory()->create($tenantAttrs);
        $user = User::factory()->create(['tenant_id' => $tenant->id]); // outlook + entra_tid
        $sub = GraphSubscription::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'subscription_id' => 'sub-'.uniqid(),
            'resource' => "users/{$user->email}/messages",
            'client_state' => 'state-secret',
            'expiration' => now()->addDay(),
        ]);

        return [$tenant, $user, $sub];
    }

    private function notify(GraphSubscription $sub): array
    {
        return [
            'subscriptionId' => $sub->subscription_id,
            'clientState' => $sub->client_state,
            'changeType' => 'created',
            'resourceData' => ['id' => 'AAMk-'.uniqid()],
        ];
    }

    public function test_notification_fetches_message_and_ingests_as_sync(): void
    {
        [$tenant, $user, $sub] = $this->userWithSubscription();

        ParseGraphNotificationJob::dispatch($tenant->id, $this->notify($sub)); // sync queue -> runs now

        $ledger = EmailActivityLedger::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('sync', $ledger->source);
        $this->assertSame(EmailDirection::Inbound, $ledger->direction);
        $this->assertSame('contact@partner.example', $ledger->from_address);
        $this->assertSame('Re: Project kickoff', $ledger->subject);
        $this->assertStringContainsString('proceed', (string) $ledger->body);
        $this->assertSame(LedgerStatus::Logged, $ledger->status); // fake SMOH matched + logged
    }

    public function test_blacklisted_internal_mail_is_dropped(): void
    {
        config()->set('mail_tracker.blacklist_domains', ['partner.example']);
        [$tenant, $user, $sub] = $this->userWithSubscription();

        ParseGraphNotificationJob::dispatch($tenant->id, $this->notify($sub));

        $this->assertSame(0, EmailActivityLedger::withoutGlobalScopes()->count());
    }

    public function test_sync_reconciles_send_time_row_by_synthetic_key(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        tenancy()->initialize($tenant);
        try {
            // Send-time row: synthetic key set, no Message-ID yet.
            $sendTime = EmailActivityLedger::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'internet_message_id' => null,
                'synthetic_key' => 'recon-key',
                'provider' => MailProvider::Outlook,
                'direction' => EmailDirection::Outbound,
                'source' => 'client',
                'status' => LedgerStatus::Logged,
            ]);

            // The sent item surfaces via sync with the same synthetic key + a real Message-ID.
            $message = EmailMessageData::fromArray([
                'internetMessageId' => '<real-msg-1@acme.com>',
                'syntheticKey' => 'recon-key',
                'subject' => 'Deal',
                'from' => ['address' => $user->email],
                'to' => [['address' => 'jane@client.com']],
                'sentAt' => '2026-07-15T10:00:00Z',
                'direction' => 'outbound',
                'provider' => 'outlook',
            ]);

            $result = app(EmailIngestionService::class)->ingest($user, $message, source: 'sync');

            $this->assertTrue($result->deduped);
            $this->assertSame(1, EmailActivityLedger::withoutGlobalScopes()->count());
            $this->assertSame('real-msg-1@acme.com', $sendTime->fresh()->internet_message_id); // backfilled
        } finally {
            tenancy()->end();
        }
    }

    public function test_renew_subscriptions_extends_expiry(): void
    {
        [$tenant, $user, $sub] = $this->userWithSubscription();
        $sub->forceFill(['expiration' => now()->addHour()])->save(); // within the renew window

        $this->artisan('graph:renew-subscriptions')->assertExitCode(0);

        $this->assertTrue($sub->fresh()->expiration->gt(now()->addDays(2)));
    }

    public function test_simulate_command_produces_a_synced_email(): void
    {
        $tenant = Tenant::factory()->create(['id' => 'demo-tenant']);
        User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'rep@demo.test']);

        $this->artisan('graph:simulate', ['--user' => 'rep@demo.test'])->assertExitCode(0);

        $this->assertSame(1, EmailActivityLedger::withoutGlobalScopes()->where('source', 'sync')->count());
    }
}
