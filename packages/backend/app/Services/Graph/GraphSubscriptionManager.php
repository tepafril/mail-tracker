<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Models\GraphSubscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Creates and renews Microsoft Graph change-notification subscriptions (Phase 2). Message
 * subscriptions cap at ~7 days (10080 min); we create shorter and renew via the daily
 * `graph:renew-subscriptions` job (MASTER-PLAN §7.3).
 */
class GraphSubscriptionManager
{
    public function __construct(private readonly GraphClientFactory $graph) {}

    /** Create (or refresh) a subscription over a user's whole mailbox. */
    public function createForUser(User $user): GraphSubscription
    {
        $resource = "users/{$user->email}/messages";
        $clientState = Str::random(40);

        $result = $this->graph->forOrg((string) $user->entra_tid)
            ->createSubscription($resource, $clientState, $this->expiration());

        return GraphSubscription::updateOrCreate(
            ['subscription_id' => $result['id']],
            [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'resource' => $resource,
                'client_state' => $clientState,
                'expiration' => Carbon::parse($result['expirationDateTime']),
            ],
        );
    }

    /** Renew subscriptions expiring within the window. Returns the count renewed. */
    public function renewExpiring(int $withinMinutes = 1440): int
    {
        $renewed = 0;

        GraphSubscription::query()->withoutGlobalScopes()
            ->where('expiration', '<', now()->addMinutes($withinMinutes))
            ->each(function (GraphSubscription $sub) use (&$renewed): void {
                if ($this->renew($sub)) {
                    $renewed++;
                }
            });

        return $renewed;
    }

    /** Renew a single subscription. Returns false if it has no usable user. */
    public function renew(GraphSubscription $subscription): bool
    {
        $user = $subscription->user;
        if ($user === null || $user->entra_tid === null) {
            return false;
        }

        $expiration = $this->graph->forOrg($user->entra_tid)
            ->renewSubscription($subscription->subscription_id, $this->expiration());

        $subscription->forceFill(['expiration' => Carbon::parse($expiration)])->save();

        return true;
    }

    private function expiration(): string
    {
        $minutes = min((int) config('services.graph.subscription_minutes', 4320), 10080);

        return now()->addMinutes($minutes)->toIso8601String();
    }
}
