<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Graph\GraphSubscriptionManager;
use Illuminate\Console\Command;

/**
 * Create Graph subscriptions for a user (or a whole tenant's Outlook users) so their
 * mailbox is tracked zero-touch (MASTER-PLAN §4.7).
 */
class GraphSubscribeCommand extends Command
{
    protected $signature = 'graph:subscribe
        {--user= : Subscribe a single user by email}
        {--tenant= : Subscribe all Outlook users of this tenant}';

    protected $description = 'Create Microsoft Graph change-notification subscriptions for user mailboxes.';

    public function handle(GraphSubscriptionManager $manager): int
    {
        $query = User::query()->withoutGlobalScopes()->where('provider', 'outlook')->whereNotNull('entra_tid');

        if ($email = $this->option('user')) {
            $query->where('email', $email);
        } elseif ($tenant = $this->option('tenant')) {
            $query->where('tenant_id', $tenant);
        } else {
            $this->error('Pass --user or --tenant.');

            return self::INVALID;
        }

        $users = $query->get();
        if ($users->isEmpty()) {
            $this->warn('No matching Outlook users found.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            try {
                $sub = $manager->createForUser($user);
                $this->info("Subscribed {$user->email} (subscription {$sub->subscription_id}).");
            } catch (\Throwable $e) {
                $this->error("Failed for {$user->email}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
