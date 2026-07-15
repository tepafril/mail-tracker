<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Graph\GraphSubscriptionManager;
use Illuminate\Console\Command;

/**
 * Renews Graph subscriptions before they expire (MASTER-PLAN §7.3). Scheduled daily.
 */
class GraphRenewSubscriptionsCommand extends Command
{
    protected $signature = 'graph:renew-subscriptions {--within=1440 : Renew subscriptions expiring within N minutes}';

    protected $description = 'Renew Microsoft Graph change-notification subscriptions nearing expiry.';

    public function handle(GraphSubscriptionManager $manager): int
    {
        $count = $manager->renewExpiring((int) $this->option('within'));
        $this->info("Renewed {$count} Graph subscription(s).");

        return self::SUCCESS;
    }
}
