<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily content-retention enforcement: scrub PII from ledger rows past each tenant's
// retention window (no-op for tenants/config with no retention set). See §7.4.
Schedule::command('mail-tracker:purge --retention --force')
    ->daily()
    ->withoutOverlapping();

// Phase 2: renew Microsoft Graph subscriptions before their ~7-day cap (§7.3).
Schedule::command('graph:renew-subscriptions')
    ->daily()
    ->withoutOverlapping();

