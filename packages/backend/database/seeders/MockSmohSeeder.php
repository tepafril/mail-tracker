<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MockSmoh\MockAccount;
use App\Models\MockSmoh\MockContact;
use App\Models\MockSmoh\MockLead;
use Illuminate\Database\Seeder;

/**
 * DEV/DEMO ONLY. Seeds the mock SMOH CRM with a few records so contact/account/lead
 * matching resolves during testing. Idempotent (keyed by email/domain).
 *
 *   php artisan db:seed --class=Database\\Seeders\\MockSmohSeeder
 */
class MockSmohSeeder extends Seeder
{
    public function run(): void
    {
        $account = MockAccount::query()->firstOrCreate(
            ['domain' => 'odad.asia'],
            ['name' => 'Odad Ltd', 'primary_email' => 'info@odad.asia'],
        );

        // A contact whose email matches the Gmail used for live outbound tests, so a real
        // "send to Gmail" auto-tracks against a known contact.
        MockContact::query()->firstOrCreate(
            ['email' => 'tepafril1992@gmail.com'],
            ['first_name' => 'Afril', 'last_name' => 'Tep', 'account_id' => $account->id],
        );

        MockContact::query()->firstOrCreate(
            ['email' => 'sales@odad.asia'],
            ['first_name' => 'Sales', 'last_name' => 'Desk', 'email_business' => 'sales@odad.asia', 'account_id' => $account->id],
        );

        MockLead::query()->firstOrCreate(
            ['email' => 'prospect@example.com'],
            ['name' => 'Prospect One', 'status' => 'new'],
        );
    }
}
