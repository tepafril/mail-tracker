<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MailProvider;
use App\Enums\TrackRule;
use App\Models\Tenant;
use App\Models\TenantIdentityMapping;
use App\Models\User;
use App\Services\Graph\GraphSubscriptionManager;
use Illuminate\Console\Command;

/**
 * Enroll specific Outlook mailboxes for zero-touch tracking WITHOUT a sign-in — the
 * admin-managed tracked list (MASTER-PLAN §7.3). Creates one User row per mailbox under
 * the tenant, keyed by email; `entra_oid` stays null and is backfilled if that user ever
 * signs in via the add-in (see {@see \App\Services\Auth\TenantResolver::provisionUser}).
 *
 * With --subscribe it also creates the Graph change-notification subscription now (needs
 * real GRAPH_CLIENT_ID/SECRET, or GRAPH_FAKE=true for a dry run).
 *
 *   php artisan mail-tracker:track-mailbox odad rep@odad.asia sales@odad.asia --subscribe
 */
class TrackMailboxCommand extends Command
{
    protected $signature = 'mail-tracker:track-mailbox
        {tenant : Tenant id the mailboxes belong to (must be onboarded for outlook)}
        {emails* : One or more mailbox email addresses to track}
        {--rule= : Auto-track rule: all | known_contacts | none (default known_contacts)}
        {--subscribe : Also create the Graph subscription for each mailbox now}';

    protected $description = 'Enroll Outlook mailboxes for zero-touch tracking (admin-managed list).';

    public function handle(GraphSubscriptionManager $subscriptions): int
    {
        $tenantId = (string) $this->argument('tenant');

        $ruleOption = $this->option('rule');
        $rule = $ruleOption !== null ? TrackRule::tryFrom((string) $ruleOption) : null;
        if ($ruleOption !== null && $rule === null) {
            $this->error("Invalid --rule \"{$ruleOption}\". Use: all | known_contacts | none.");

            return self::INVALID;
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant instanceof Tenant) {
            $this->error("Unknown tenant \"{$tenantId}\". Onboard it first with mail-tracker:onboard.");

            return self::INVALID;
        }

        $mapping = TenantIdentityMapping::query()
            ->where('provider', MailProvider::Outlook->value)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($mapping === null) {
            $this->error("Tenant \"{$tenantId}\" has no outlook org mapping. Run: mail-tracker:onboard outlook <entra-tid> --tenant={$tenantId}");

            return self::INVALID;
        }

        $entraTid = $mapping->external_org_id;

        tenancy()->initialize($tenant);

        try {
            foreach ((array) $this->argument('emails') as $rawEmail) {
                $email = mb_strtolower(trim((string) $rawEmail));
                if ($email === '') {
                    continue;
                }

                $user = User::query()->firstOrCreate(
                    ['provider' => MailProvider::Outlook->value, 'email' => $email],
                    ['tenant_id' => $tenant->id, 'entra_tid' => $entraTid, 'track_rule' => ($rule ?? TrackRule::default())->value],
                );

                // Keep entra_tid current even if the row pre-existed (e.g. org re-onboarded);
                // update the rule too when one was explicitly passed.
                $updates = [];
                if ($user->entra_tid !== $entraTid) {
                    $updates['entra_tid'] = $entraTid;
                }
                if ($rule !== null && $user->track_rule !== $rule) {
                    $updates['track_rule'] = $rule->value;
                }
                if ($updates !== []) {
                    $user->forceFill($updates)->save();
                }

                $this->info(sprintf(
                    '%s: %s (user #%d, rule=%s)',
                    $email,
                    $user->wasRecentlyCreated ? 'enrolled' : 'already enrolled',
                    $user->id,
                    $user->track_rule->value,
                ));

                if ($this->option('subscribe')) {
                    $sub = $subscriptions->createForUser($user);
                    $this->line("  subscribed ({$sub->subscription_id}, expires {$sub->expiration})");
                }
            }
        } finally {
            tenancy()->end();
        }

        return self::SUCCESS;
    }
}
