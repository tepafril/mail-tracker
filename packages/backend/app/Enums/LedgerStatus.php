<?php

declare(strict_types=1);

namespace App\Enums;

/** Lifecycle of an email_activity_ledger row. */
enum LedgerStatus: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case Logged = 'logged';
    case SkippedNoContact = 'skipped_no_contact';
    case SkippedBlacklisted = 'skipped_blacklisted';
    case Failed = 'failed';
}
