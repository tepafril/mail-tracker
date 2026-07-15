<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ParseGraphNotificationJob;
use App\Models\GraphSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Graph\GraphSubscriptionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * DEV/DEMO ONLY. Simulates a Graph change notification end-to-end (requires GRAPH_FAKE=true):
 * ensures a subscription exists, then dispatches ParseGraphNotificationJob with a synthetic
 * notification. The FakeGraphClient returns a canned inbound message, which flows through
 * the real ingestion pipeline and shows up in /dev/emails — so you can see zero-touch sync
 * work with no real Graph, app registration, or public webhook.
 */
class GraphSimulateNotificationCommand extends Command
{
    protected $signature = 'graph:simulate {--user=rep@demo.test : mailbox owner to simulate an inbound email for}';

    protected $description = 'DEV: simulate a Microsoft Graph notification end-to-end (needs GRAPH_FAKE=true).';

    public function handle(GraphSubscriptionManager $manager): int
    {
        if (! config('services.graph.fake')) {
            $this->error('Set GRAPH_FAKE=true to simulate (this must not hit real Graph).');

            return self::INVALID;
        }

        $email = (string) $this->option('user');
        $user = User::query()->withoutGlobalScopes()->where('email', $email)->first();
        if ($user === null) {
            $this->error("No user [{$email}]. Run the demo (dev-login) first, or pass --user=<email>.");

            return self::INVALID;
        }

        $tenant = Tenant::find($user->tenant_id);
        tenancy()->initialize($tenant);
        try {
            $subscription = GraphSubscription::query()->withoutGlobalScopes()->where('user_id', $user->id)->first()
                ?? $manager->createForUser($user);
        } finally {
            tenancy()->end();
        }

        ParseGraphNotificationJob::dispatch($user->tenant_id, [
            'subscriptionId' => $subscription->subscription_id,
            'clientState' => $subscription->client_state,
            'changeType' => 'created',
            'resourceData' => ['id' => 'sim-'.Str::random(10)],
        ]);

        $this->info("Simulated an inbound email for {$user->email}. View it at /dev/emails.");

        return self::SUCCESS;
    }
}
