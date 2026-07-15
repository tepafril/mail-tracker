<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GraphSubscription;
use App\Models\Tenant;
use App\Services\Email\BlacklistFilter;
use App\Services\Email\EmailIngestionService;
use App\Services\Graph\GraphClientFactory;
use App\Services\Graph\GraphException;
use App\Services\Graph\GraphMessageMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * PHASE 2 (zero-touch sync). Processes one verified Microsoft Graph change notification:
 * resolve the subscription's user, fetch the message from Graph, drop internal/blacklisted
 * mail, and ingest it through the SAME dedup ledger as the client path (source: 'sync').
 */
class ParseGraphNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 300, 600];

    /**
     * @param  array<string, mixed>  $notification
     */
    public function __construct(
        public string $tenantId,
        public array $notification,
    ) {}

    public function handle(GraphClientFactory $graph, EmailIngestionService $ingestion, BlacklistFilter $blacklist): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant instanceof Tenant) {
            return;
        }
        if (! tenancy()->initialized || tenant()->getTenantKey() !== $tenant->getTenantKey()) {
            tenancy()->initialize($tenant);
        }

        $subscriptionId = (string) ($this->notification['subscriptionId'] ?? '');
        $messageId = (string) data_get($this->notification, 'resourceData.id', '');
        if ($subscriptionId === '' || $messageId === '') {
            return;
        }

        $subscription = GraphSubscription::withoutGlobalScopes()
            ->where('subscription_id', $subscriptionId)
            ->first();
        $user = $subscription?->user;
        if ($user === null || $user->entra_tid === null) {
            Log::warning('Graph notification for unknown subscription/user.', ['subscription' => $subscriptionId]);

            return;
        }

        try {
            $raw = $graph->forOrg($user->entra_tid)->getMessage($user->email, $messageId);
        } catch (GraphException $e) {
            if ($e->status === 404) {
                return; // message no longer exists — nothing to log
            }
            throw $e; // transient — let the queue retry
        }

        $message = GraphMessageMapper::toEmailMessage($raw, $user);

        // Drop internal/colleague mail (only on the background path — §7.3).
        $counterparty = $message->counterpartyEmail();
        if ($counterparty !== null && $blacklist->isBlacklisted($counterparty)) {
            return;
        }

        $ingestion->ingest($user, $message, source: 'sync');
    }
}
