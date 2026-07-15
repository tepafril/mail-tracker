<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmailActivityLedger;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Retention / GDPR erasure for stored email content (MASTER-PLAN §7.4, §10).
 *
 * Default mode `scrub` nulls the PII columns (subject, from/to/cc/bcc, body) and stamps
 * `content_purged_at`, while KEEPING the dedup keys + audit fields — so erased mail is
 * never silently re-logged. Mode `delete` removes the rows entirely (loses the dedup
 * tombstone; use only for hard removal).
 *
 * Scope is required: pass --before, --tenant, or --retention (never purges unscoped).
 */
class PurgeEmailContentCommand extends Command
{
    protected $signature = 'mail-tracker:purge
        {--tenant= : Restrict to a single tenant id}
        {--before= : Purge rows created before this (Carbon-parseable, e.g. "-90 days" or 2026-01-01)}
        {--mode=scrub : scrub (null PII, keep keys) | delete (remove rows)}
        {--retention : Apply each tenant\'s configured retention window (ignores --before)}
        {--dry-run : Report what would be purged without changing anything}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Scrub or delete stored email content for retention / GDPR erasure.';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['scrub', 'delete'], true)) {
            $this->error("Invalid --mode [{$mode}]; use scrub or delete.");

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('retention')) {
            return $this->runRetention($mode, $dryRun);
        }

        $tenantId = $this->option('tenant');
        $before = $this->resolveBefore($this->option('before'));

        if ($tenantId === null && $before === null) {
            $this->error('Refusing to purge without a scope. Pass --tenant, --before, or --retention.');

            return self::INVALID;
        }

        $desc = 'tenant='.($tenantId ?? 'ALL').', before='.($before?->toDateTimeString() ?? 'ANY');
        $this->runPurge($this->baseQuery($tenantId, $before, $mode), $mode, $dryRun, $desc, confirm: ! $this->option('force'));

        return self::SUCCESS;
    }

    /** Apply per-tenant retention windows (used by the scheduler). Non-interactive. */
    private function runRetention(string $mode, bool $dryRun): int
    {
        Tenant::query()->each(function (Tenant $tenant) use ($mode, $dryRun): void {
            $days = $tenant->effectiveRetentionDays();
            if ($days === null) {
                return; // retain indefinitely
            }

            $cutoff = CarbonImmutable::now()->subDays($days);
            $this->runPurge(
                $this->baseQuery($tenant->id, $cutoff, $mode),
                $mode,
                $dryRun,
                "tenant={$tenant->id}, retention={$days}d (before {$cutoff->toDateString()})",
                confirm: false,
            );
        });

        return self::SUCCESS;
    }

    /**
     * @return Builder<EmailActivityLedger>
     */
    private function baseQuery(?string $tenantId, ?CarbonImmutable $before, string $mode): Builder
    {
        $query = EmailActivityLedger::query()->withoutGlobalScopes();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        if ($before !== null) {
            $query->where('created_at', '<', $before);
        }
        // Scrub only rows that still hold content; delete targets all matches.
        if ($mode === 'scrub') {
            $query->whereNull('content_purged_at');
        }

        return $query;
    }

    /**
     * @param  Builder<EmailActivityLedger>  $query
     */
    private function runPurge(Builder $query, string $mode, bool $dryRun, string $desc, bool $confirm): void
    {
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->line("Nothing to {$mode} ({$desc}).");

            return;
        }

        if ($dryRun) {
            $this->info("[dry-run] would {$mode} {$count} row(s) — {$desc}.");

            return;
        }

        if ($confirm && ! $this->confirm("{$mode} {$count} row(s)? ({$desc})")) {
            $this->warn('Aborted.');

            return;
        }

        if ($mode === 'delete') {
            $query->delete();
        } else {
            $query->update([
                ...array_fill_keys(EmailActivityLedger::PII_COLUMNS, null),
                'content_purged_at' => CarbonImmutable::now(),
            ]);
        }

        $this->info(($mode === 'delete' ? 'Deleted ' : 'Scrubbed ')."{$count} row(s) — {$desc}.");
    }

    private function resolveBefore(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            $this->error("Could not parse --before value [{$value}].");
            exit(self::INVALID);
        }
    }
}
