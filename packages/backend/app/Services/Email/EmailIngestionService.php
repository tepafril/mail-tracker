<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\DataObjects\EmailMessageData;
use App\DataObjects\IngestionResult;
use App\DataObjects\MailAddressData;
use App\Enums\LedgerStatus;
use App\Jobs\LogEmailActivityJob;
use App\Models\EmailActivityLedger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Entry point for logging an email. Enforces per-tenant idempotency against the dedup
 * ledger, then dispatches {@see LogEmailActivityJob} to do the SMOH match + write
 * asynchronously. Shared by the client one-click endpoint and (Phase 2) the sync engine.
 *
 * Assumes tenancy is already initialized (the ledger is tenant-scoped).
 */
class EmailIngestionService
{
    /**
     * @param  string  $source  'client' | 'sync'
     */
    public function ingest(User $user, EmailMessageData $message, string $source = 'client'): IngestionResult
    {
        if ($existing = $this->findExisting($message)) {
            $this->reconcile($existing, $message);

            return new IngestionResult($existing, deduped: true);
        }

        try {
            $ledger = EmailActivityLedger::create([
                'user_id' => $user->id,
                'internet_message_id' => $message->internetMessageId,
                'synthetic_key' => $message->syntheticKey !== '' ? $message->syntheticKey : null,
                'provider' => $message->provider,
                'direction' => $message->direction,
                'source' => $source,
                'subject' => mb_substr($message->subject, 0, 255),
                'status' => LedgerStatus::Pending,
                // Full content, encrypted at rest. Body is sanitized before storage.
                'from_address' => $message->from->address !== '' ? $message->from->address : null,
                'to_recipients' => $this->recipients($message->to),
                'cc_recipients' => $this->recipients($message->cc),
                'bcc_recipients' => $this->recipients($message->bcc),
                'body' => BodySanitizer::sanitize($message->body, $message->bodyType),
                'body_type' => $message->bodyType,
                'email_sent_at' => $this->parseSentAt($message->sentAt),
            ]);
        } catch (QueryException $e) {
            // Lost a race on the UNIQUE(tenant_id, …) index — treat as a dedup hit.
            if ($existing = $this->findExisting($message)) {
                return new IngestionResult($existing, deduped: true);
            }
            throw $e;
        }

        LogEmailActivityJob::dispatch($ledger->id, $user->tenant_id, $message);

        return new IngestionResult($ledger, deduped: false);
    }

    /**
     * Reconcile a send-time row (synthetic key, no Message-ID yet) with the real
     * Message-ID once the sent item surfaces via sync (MASTER-PLAN §7.2).
     */
    private function reconcile(EmailActivityLedger $existing, EmailMessageData $message): void
    {
        if ($existing->internet_message_id !== null || $message->internetMessageId === null) {
            return;
        }

        try {
            $existing->forceFill(['internet_message_id' => $message->internetMessageId])->save();
        } catch (QueryException) {
            // Another row already owns that Message-ID (UNIQUE) — leave as-is.
        }
    }

    /**
     * @param  list<MailAddressData>  $addresses
     * @return list<array{address: string, name: string|null}>|null
     */
    private function recipients(array $addresses): ?array
    {
        if ($addresses === []) {
            return null;
        }

        return array_map(
            static fn (MailAddressData $a): array => ['address' => $a->address, 'name' => $a->name],
            $addresses,
        );
    }

    private function parseSentAt(string $sentAt): ?CarbonImmutable
    {
        if ($sentAt === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($sentAt);
        } catch (\Throwable) {
            return null;
        }
    }

    private function findExisting(EmailMessageData $message): ?EmailActivityLedger
    {
        if ($message->internetMessageId !== null) {
            $row = EmailActivityLedger::query()
                ->where('internet_message_id', $message->internetMessageId)
                ->first();
            if ($row) {
                return $row;
            }
        }

        if ($message->syntheticKey !== '') {
            return EmailActivityLedger::query()
                ->where('synthetic_key', $message->syntheticKey)
                ->first();
        }

        return null;
    }
}
