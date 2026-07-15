<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\HandleGraphLifecycleJob;
use App\Jobs\ParseGraphNotificationJob;
use App\Models\GraphSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Microsoft Graph change-notification webhook (MASTER-PLAN §4.7). Public endpoint —
 * authenticity is established per-notification via the `clientState` shared secret.
 *
 * Two request shapes:
 *  1. Subscription-validation handshake: a `?validationToken` query param must be
 *     echoed back as `text/plain` with 200 within 10s.
 *  2. Real notifications (and lifecycle events): verify `clientState`, enqueue, and
 *     return 202 within 3s — Graph deliberately keeps the handshake fast.
 */
class GraphNotificationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // 1. Validation handshake. Symfony has already URL-decoded the query value, so
        //    we echo it directly (an extra urldecode would double-decode). Guard against
        //    a malformed array-shaped query param (?validationToken[]=x) — genuine Graph
        //    always sends a scalar.
        $validationToken = $request->query('validationToken');
        if (is_string($validationToken)) {
            return response($validationToken, 200)->header('Content-Type', 'text/plain');
        }

        // 2. Notifications. Verify each against its stored subscription's clientState.
        $notifications = (array) $request->input('value', []);

        foreach ($notifications as $notification) {
            if (! is_array($notification)) {
                continue;
            }

            $subscriptionId = $notification['subscriptionId'] ?? null;
            if (! is_string($subscriptionId)) {
                continue;
            }

            $subscription = GraphSubscription::withoutGlobalScopes()
                ->where('subscription_id', $subscriptionId)
                ->first();

            if (! $subscription instanceof GraphSubscription) {
                continue; // unknown subscription — ignore
            }

            $clientState = (string) ($notification['clientState'] ?? '');
            if (! hash_equals($subscription->client_state, $clientState)) {
                continue; // spoofed clientState — drop silently
            }

            // Lifecycle events (reauthorizationRequired / subscriptionRemoved / missed)
            // carry a `lifecycleEvent` instead of `resourceData`.
            if (isset($notification['lifecycleEvent'])) {
                HandleGraphLifecycleJob::dispatch(
                    $subscription->tenant_id,
                    $subscriptionId,
                    (string) $notification['lifecycleEvent'],
                )->onQueue('webhooks');

                continue;
            }

            ParseGraphNotificationJob::dispatch($subscription->tenant_id, $notification)
                ->onQueue('webhooks');
        }

        // Always 202 fast; processing is async.
        return response('', 202);
    }
}
