<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EmailDirection;
use App\Enums\LedgerStatus;
use App\Enums\MailProvider;
use App\Models\EmailActivityLedger;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurgeEmailContentTest extends TestCase
{
    use RefreshDatabase;

    private function makeRow(string $tenantId, Carbon $createdAt, array $overrides = []): EmailActivityLedger
    {
        $row = new EmailActivityLedger(array_merge([
            'tenant_id' => $tenantId,
            'internet_message_id' => 'msg-'.Str::random(8),
            'synthetic_key' => Str::random(12),
            'provider' => MailProvider::Outlook,
            'direction' => EmailDirection::Outbound,
            'source' => 'client',
            'status' => LedgerStatus::Logged,
            'contact_id' => (string) Str::uuid(),
            'subject' => 'Secret subject',
            'from_address' => 'rep@acme.com',
            'to_recipients' => [['address' => 'jane@client.com', 'name' => 'Jane']],
            'body' => 'secret body content',
            'body_type' => 'text',
        ], $overrides));
        $row->save();

        EmailActivityLedger::withoutGlobalScopes()->where('id', $row->id)->update(['created_at' => $createdAt]);

        return EmailActivityLedger::withoutGlobalScopes()->findOrFail($row->id);
    }

    public function test_scrub_before_nulls_pii_keeps_keys_and_stamps_purged_at(): void
    {
        $tenant = Tenant::factory()->create();
        $old = $this->makeRow($tenant->id, now()->subDays(100));
        $recent = $this->makeRow($tenant->id, now()->subDays(2));

        $this->artisan('mail-tracker:purge', ['--before' => '-30 days', '--force' => true])
            ->assertExitCode(0);

        $old = $old->fresh();
        // PII scrubbed...
        $this->assertNull($old->body);
        $this->assertNull($old->subject);
        $this->assertNull($old->from_address);
        $this->assertNull($old->to_recipients);
        $this->assertNotNull($old->content_purged_at);
        // ...but dedup keys + audit linkage kept (so it won't be re-logged).
        $this->assertNotNull($old->internet_message_id);
        $this->assertNotNull($old->contact_id);
        $this->assertSame(LedgerStatus::Logged, $old->status);

        // Recent row untouched.
        $this->assertSame('secret body content', $recent->fresh()->body);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $old = $this->makeRow($tenant->id, now()->subDays(100));

        $this->artisan('mail-tracker:purge', ['--before' => '-30 days', '--dry-run' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame('secret body content', $old->fresh()->body);
        $this->assertNull($old->fresh()->content_purged_at);
    }

    public function test_delete_mode_removes_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $old = $this->makeRow($tenant->id, now()->subDays(100));

        $this->artisan('mail-tracker:purge', ['--before' => '-30 days', '--mode' => 'delete', '--force' => true])
            ->assertExitCode(0);

        $this->assertNull(EmailActivityLedger::withoutGlobalScopes()->find($old->id));
    }

    public function test_retention_respects_per_tenant_window(): void
    {
        $tenant = Tenant::factory()->create(['content_retention_days' => 30]);
        $keepForever = Tenant::factory()->create(['content_retention_days' => null]);

        $old = $this->makeRow($tenant->id, now()->subDays(90));
        $otherOld = $this->makeRow($keepForever->id, now()->subDays(90));

        // No global default, so the null-retention tenant keeps everything.
        config()->set('mail_tracker.retention_days', null);

        $this->artisan('mail-tracker:purge', ['--retention' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertNull($old->fresh()->body);                 // 30d tenant -> scrubbed
        $this->assertSame('secret body content', $otherOld->fresh()->body); // retained
    }

    public function test_refuses_without_scope(): void
    {
        $this->artisan('mail-tracker:purge', ['--force' => true])
            ->assertExitCode(2); // INVALID
    }
}
