<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GmailWatch;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * PHASE 2 (zero-touch sync). Processes a Gmail Pub/Sub push for one watched mailbox.
 *
 * Intended flow (see MASTER-PLAN §6.4/§7.3), NOT yet implemented — requires the user's
 * offline Gmail OAuth grant:
 *   1. Initialize tenancy for $tenantId (done below).
 *   2. users.history.list(startHistoryId = watch.history_id) to diff what changed.
 *   3. For messages gaining SENT (outbound) or arriving in INBOX (inbound): fetch,
 *      apply BlacklistFilter, build EmailMessageData, ingest with source 'sync'.
 *   4. Advance watch.history_id to the new historyId.
 *   On a 404 (historyId too old), do a full delta catch-up reconciliation.
 */
class GmailHistoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 300, 600];

    public function __construct(
        public string $tenantId,
        public int $watchId,
        public int $historyId,
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant instanceof Tenant) {
            return;
        }

        if (! tenancy()->initialized || tenant()->getTenantKey() !== $tenant->getTenantKey()) {
            tenancy()->initialize($tenant);
        }

        $watch = GmailWatch::find($this->watchId);
        if (! $watch instanceof GmailWatch) {
            return;
        }

        // TODO(Phase 2): call users.history.list and ingest changed messages.
        Log::info('Gmail push received (Phase 2 sync not yet implemented).', [
            'tenant' => $this->tenantId,
            'watch' => $this->watchId,
            'historyId' => $this->historyId,
        ]);
    }
}
