<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataObjects\EmailMessageData;
use App\Enums\LedgerStatus;
use App\Enums\TrackRule;
use App\Models\EmailActivityLedger;
use App\Models\Tenant;
use App\Models\TenantIdentityMapping;
use App\Models\User;
use App\Services\Email\EmailIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-mailbox auto-track rules (Dynamics-style: all / known_contacts / none).
 * Uses the in-process fakes; the fake SMOH matches every address, so a match is
 * suppressed by sending a message with no counterparty.
 */
class TrackRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.graph.fake', true);
        config()->set('services.smoh.fake', true);
    }

    private function userWithRule(TrackRule $rule): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->create(['tenant_id' => $tenant->id, 'track_rule' => $rule]);
    }

    /** Ingest a message for the user and return the resulting ledger row (job runs sync in tests). */
    private function ingest(User $user, array $overrides = []): EmailActivityLedger
    {
        $message = EmailMessageData::fromArray(array_merge([
            'internetMessageId' => '<'.uniqid().'@test>',
            'subject' => 'Hi',
            'from' => ['address' => $user->email],
            'to' => [['address' => 'client@example.com']],
            'direction' => 'outbound',
            'provider' => 'outlook',
            'sentAt' => '2026-07-16T00:00:00Z',
        ], $overrides));

        tenancy()->initialize(Tenant::findOrFail($user->tenant_id));
        try {
            app(EmailIngestionService::class)->ingest($user, $message, source: 'sync');
        } finally {
            tenancy()->end();
        }

        return EmailActivityLedger::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_known_contacts_logs_a_matched_message(): void
    {
        $ledger = $this->ingest($this->userWithRule(TrackRule::KnownContacts));

        $this->assertSame(LedgerStatus::Logged, $ledger->status);
    }

    public function test_none_skips_by_rule(): void
    {
        $ledger = $this->ingest($this->userWithRule(TrackRule::None));

        $this->assertSame(LedgerStatus::SkippedByRule, $ledger->status);
    }

    public function test_known_contacts_skips_when_no_counterparty(): void
    {
        // Outbound with no recipients => no counterparty => nothing to match against.
        $ledger = $this->ingest($this->userWithRule(TrackRule::KnownContacts), ['to' => []]);

        $this->assertSame(LedgerStatus::SkippedNoContact, $ledger->status);
    }

    public function test_all_logs_even_without_a_match(): void
    {
        // 'all' logs regardless; with no counterparty the regarding is omitted (contact_id null).
        $ledger = $this->ingest($this->userWithRule(TrackRule::All), ['to' => []]);

        $this->assertSame(LedgerStatus::Logged, $ledger->status);
        $this->assertNull($ledger->contact_id);
    }

    public function test_enrollment_defaults_to_known_contacts(): void
    {
        $this->onboard();

        $this->artisan('mail-tracker:track-mailbox', ['tenant' => 'odad', 'emails' => ['a@odad.asia']])
            ->assertExitCode(0);

        $this->assertSame(
            TrackRule::KnownContacts,
            User::withoutGlobalScopes()->where('email', 'a@odad.asia')->firstOrFail()->track_rule,
        );
    }

    public function test_command_sets_and_updates_rule(): void
    {
        $this->onboard();
        $args = ['tenant' => 'odad', 'emails' => ['a@odad.asia']];

        $this->artisan('mail-tracker:track-mailbox', $args + ['--rule' => 'none'])->assertExitCode(0);
        $this->assertSame(TrackRule::None, User::withoutGlobalScopes()->where('email', 'a@odad.asia')->firstOrFail()->track_rule);

        // Re-run with a different rule updates the existing row.
        $this->artisan('mail-tracker:track-mailbox', $args + ['--rule' => 'all'])->assertExitCode(0);
        $this->assertSame(TrackRule::All, User::withoutGlobalScopes()->where('email', 'a@odad.asia')->firstOrFail()->track_rule);
    }

    public function test_command_rejects_invalid_rule(): void
    {
        $this->onboard();

        $this->artisan('mail-tracker:track-mailbox', ['tenant' => 'odad', 'emails' => ['a@odad.asia'], '--rule' => 'bogus'])
            ->assertExitCode(2); // Command::INVALID
    }

    private function onboard(): void
    {
        Tenant::factory()->create(['id' => 'odad']);
        TenantIdentityMapping::create([
            'provider' => 'outlook',
            'external_org_id' => 'org-123',
            'tenant_id' => 'odad',
        ]);
    }
}
