<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailProvider;
use App\Models\GraphSubscription;
use App\Models\Tenant;
use App\Models\TenantIdentityMapping;
use App\Models\User;
use App\Services\Auth\TenantResolver;
use App\Services\Auth\VerifiedIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin-managed enrollment (mail-tracker:track-mailbox) for zero-touch tracking without a
 * sign-in, plus the sign-in reuse/backfill safeguard in TenantResolver.
 */
class TrackMailboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.graph.fake', true); // --subscribe needs no real network
    }

    private function onboard(string $entraTid = 'org-123', string $tenantId = 'odad'): Tenant
    {
        $tenant = Tenant::factory()->create(['id' => $tenantId]);
        TenantIdentityMapping::create([
            'provider' => MailProvider::Outlook->value,
            'external_org_id' => $entraTid,
            'tenant_id' => $tenant->id,
        ]);

        return $tenant;
    }

    public function test_enrolls_mailbox_as_outlook_user_with_entra_tid(): void
    {
        $this->onboard('org-123');

        $this->artisan('mail-tracker:track-mailbox', ['tenant' => 'odad', 'emails' => ['Rep@Odad.Asia']])
            ->assertExitCode(0);

        $user = User::withoutGlobalScopes()->where('email', 'rep@odad.asia')->firstOrFail();
        $this->assertSame('odad', $user->tenant_id);
        $this->assertSame(MailProvider::Outlook, $user->provider);
        $this->assertSame('org-123', $user->entra_tid);
        $this->assertNull($user->entra_oid); // no sign-in yet
    }

    public function test_subscribe_flag_creates_graph_subscription(): void
    {
        $this->onboard();

        $this->artisan('mail-tracker:track-mailbox', [
            'tenant' => 'odad',
            'emails' => ['rep@odad.asia'],
            '--subscribe' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, GraphSubscription::withoutGlobalScopes()->count());
    }

    public function test_reenrolling_is_idempotent(): void
    {
        $this->onboard();
        $args = ['tenant' => 'odad', 'emails' => ['rep@odad.asia']];

        $this->artisan('mail-tracker:track-mailbox', $args)->assertExitCode(0);
        $this->artisan('mail-tracker:track-mailbox', $args)->assertExitCode(0);

        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'rep@odad.asia')->count());
    }

    public function test_signin_reuses_enrolled_row_and_backfills_oid(): void
    {
        $tenant = $this->onboard('org-xyz');
        $this->artisan('mail-tracker:track-mailbox', ['tenant' => 'odad', 'emails' => ['rep@odad.asia']])
            ->assertExitCode(0);

        $identity = new VerifiedIdentity(
            provider: MailProvider::Outlook,
            subject: 'oid-999',
            orgId: 'org-xyz',
            email: 'rep@odad.asia',
            name: 'Rep',
        );
        $user = app(TenantResolver::class)->provisionUser($tenant, $identity);

        // One row, not two — the sign-in adopted the enrolled mailbox and backfilled the oid.
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'rep@odad.asia')->count());
        $this->assertSame('oid-999', $user->entra_oid);
        $this->assertSame('org-xyz', $user->entra_tid);
    }

    public function test_unknown_tenant_fails(): void
    {
        $this->artisan('mail-tracker:track-mailbox', ['tenant' => 'nope', 'emails' => ['x@y.com']])
            ->assertExitCode(2); // Command::INVALID
    }

    public function test_tenant_without_outlook_mapping_fails(): void
    {
        Tenant::factory()->create(['id' => 'odad']);

        $this->artisan('mail-tracker:track-mailbox', ['tenant' => 'odad', 'emails' => ['x@odad.asia']])
            ->assertExitCode(2);
    }
}
