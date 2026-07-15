<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GraphSubscription;
use App\Models\Tenant;
use App\Services\Graph\GraphSubscriptionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * PHASE 2. Handles a Microsoft Graph subscription lifecycle event (MASTER-PLAN §4.7):
 *  - reauthorizationRequired → renew (reauthorize) the subscription.
 *  - subscriptionRemoved      → recreate it for the user, drop the stale row.
 *  - missed                   → flag for delta catch-up reconciliation.
 */
class HandleGraphLifecycleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 300, 600];

    public function __construct(
        public string $tenantId,
        public string $subscriptionId,
        public string $lifecycleEvent,
    ) {}

    public function handle(GraphSubscriptionManager $manager): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant instanceof Tenant) {
            return;
        }
        if (! tenancy()->initialized || tenant()->getTenantKey() !== $tenant->getTenantKey()) {
            tenancy()->initialize($tenant);
        }

        $subscription = GraphSubscription::withoutGlobalScopes()
            ->where('subscription_id', $this->subscriptionId)
            ->first();
        if (! $subscription instanceof GraphSubscription) {
            return;
        }

        match ($this->lifecycleEvent) {
            'reauthorizationRequired' => $manager->renew($subscription),
            'subscriptionRemoved' => $this->recreate($manager, $subscription),
            'missed' => Log::warning('Graph "missed" lifecycle — delta reconciliation needed.', [
                'tenant' => $this->tenantId, 'subscription' => $this->subscriptionId,
            ]),
            default => Log::info('Unhandled Graph lifecycle event.', ['event' => $this->lifecycleEvent]),
        };
    }

    private function recreate(GraphSubscriptionManager $manager, GraphSubscription $subscription): void
    {
        $user = $subscription->user;
        if ($user !== null) {
            $manager->createForUser($user);
        }
        $subscription->delete();
    }
}
