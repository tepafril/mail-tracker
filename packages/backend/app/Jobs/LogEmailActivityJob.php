<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DataObjects\EmailMessageData;
use App\Enums\LedgerStatus;
use App\Enums\TrackRule;
use App\Models\EmailActivityLedger;
use App\Models\EmailTrackAudit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Email\BodySanitizer;
use App\Services\Smoh\SmohClientFactory;
use App\Services\Smoh\SmohException;
use App\Services\Smoh\SmohThrottleException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Matches a captured email to a SMOH contact and writes the CRM.Email activity
 * (MASTER-PLAN §4.6). Idempotent per ledger row; backs off on SMOH 429.
 */
class LogEmailActivityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** @var array<int, int> escalating backoff (seconds) across retries */
    public array $backoff = [10, 30, 60, 300, 600];

    /**
     * Cap on UNCAUGHT exceptions (genuine SMOH failures), NOT total attempts. We must
     * not use `$tries`, because a 429 handled via `release()` still increments the
     * queue's attempt count — with `$tries` a throttled tenant would exhaust attempts
     * and permanently fail, the exact case SmohThrottleException exists to survive.
     * `$maxExceptions` only counts thrown exceptions, so throttle-release loops don't
     * burn it, while a defined retryUntil() bounds the overall lifetime.
     */
    public int $maxExceptions = 8;

    /** Auto-expire the ShouldBeUnique lock so a crashed worker can't wedge a ledger row. */
    public int $uniqueFor = 21600; // 6h, matches the retryUntil() window

    public function __construct(
        public int $ledgerId,
        public string $tenantId,
        public EmailMessageData $message,
    ) {
        $this->onQueue('webhooks');
    }

    /** One in-flight job per ledger row. */
    public function uniqueId(): string
    {
        return 'log-email-'.$this->ledgerId;
    }

    /**
     * Overall lifetime cap. Because throttle releases don't consume `$maxExceptions`,
     * this time bound is what ultimately stops a permanently-throttled job. Laravel
     * ignores the (deliberately unset) `$tries` when retryUntil() is defined.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(SmohClientFactory $factory): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant instanceof Tenant) {
            return; // tenant vanished; nothing to do
        }

        // Ensure we run under this tenant's context (idempotent if already initialized).
        if (! tenancy()->initialized || tenant()->getTenantKey() !== $tenant->getTenantKey()) {
            tenancy()->initialize($tenant);
        }

        $ledger = EmailActivityLedger::find($this->ledgerId);
        if (! $ledger instanceof EmailActivityLedger || $ledger->status === LedgerStatus::Logged) {
            return; // already logged or gone
        }

        $ledger->increment('attempts');

        // The per-mailbox track rule governs AUTO (sync) tracking only. A manual
        // "Log to CRM" (source=client) always logs, matched or not — like Dynamics' Track.
        $isSync = $ledger->source === 'sync';
        $rule = User::find($ledger->user_id)?->track_rule ?? TrackRule::default();

        if ($isSync && $rule === TrackRule::None) {
            $this->finish($ledger, LedgerStatus::SkippedByRule, 'track-rule:none', $tenant);

            return;
        }

        // Auto-tracking (except the "all" rule) only logs mail matched to a CRM record.
        $requireMatch = $isSync && $rule !== TrackRule::All;

        $counterparty = $this->message->counterpartyEmail();
        $client = $factory->for($tenant);

        try {
            // Match the counterparty to a CRM record: contact -> lead -> account.
            $match = $counterparty !== null ? $client->resolveRecipient($counterparty) : null;

            if ($match === null && $requireMatch) {
                $reason = $counterparty === null ? 'no-counterparty' : 'no-record-match:'.$counterparty;
                $this->finish($ledger, LedgerStatus::SkippedNoContact, $reason, $tenant);

                return;
            }

            $activityId = $client->logEmailActivity(
                $this->message->toSmohActivity($match?->id, $match?->type, $this->buildBody()),
            );

            $ledger->fill([
                'contact_id' => $match?->id,
                'regarding_type' => $match?->type,
                'smoh_activity_id' => $activityId,
                'status' => LedgerStatus::Logged,
                'logged_at' => now(),
                'last_error' => null,
            ])->save();

            $this->audit($tenant, 'logged', 201, $activityId);
        } catch (SmohThrottleException $e) {
            // Don't consume a retry for throttling — release with the server's hint.
            $this->release($e->retryAfter ?? 60);
        } catch (SmohException $e) {
            $ledger->fill(['status' => LedgerStatus::Failed, 'last_error' => $e->getMessage()])->save();
            $this->audit($tenant, 'failed', $e->status, $e->getMessage());
            throw $e; // let the queue retry per $backoff/$tries
        }
    }

    /** Mark the ledger terminal, write an audit row. */
    private function finish(EmailActivityLedger $ledger, LedgerStatus $status, string $detail, Tenant $tenant): void
    {
        $ledger->fill(['status' => $status, 'last_error' => null])->save();
        $this->audit($tenant, $status->value, null, $detail);
    }

    private function buildBody(): string
    {
        if (! config('mail_tracker.store_body', true)) {
            return '';
        }

        $body = BodySanitizer::sanitize($this->message->body, $this->message->bodyType);

        return mb_substr($body, 0, (int) config('mail_tracker.max_body_length', 100_000));
    }

    private function audit(Tenant $tenant, string $outcome, ?int $smohStatus, ?string $detail): void
    {
        EmailTrackAudit::create([
            'tenant_id' => $tenant->id,
            'user_id' => EmailActivityLedger::find($this->ledgerId)?->user_id,
            'provider' => $this->message->provider->value,
            'internet_message_id' => $this->message->internetMessageId,
            'synthetic_key' => $this->message->syntheticKey !== '' ? $this->message->syntheticKey : null,
            'outcome' => $outcome,
            'smoh_status' => $smohStatus,
            'detail' => $detail,
        ]);
    }

    /** Called by the queue after all retries are exhausted. */
    public function failed(\Throwable $e): void
    {
        $ledger = EmailActivityLedger::withoutGlobalScopes()->find($this->ledgerId);
        $ledger?->fill(['status' => LedgerStatus::Failed, 'last_error' => $e->getMessage()])->save();
    }
}
