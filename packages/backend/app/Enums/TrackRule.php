<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-mailbox automatic-tracking rule, mirroring Dynamics 365's tracking options.
 * Set per enrolled mailbox via `mail-tracker:track-mailbox … --rule=`.
 *
 * `responses_only` (track only replies to CRM email) is reserved for Phase D, once
 * conversation correlation exists — see DYNAMICS-PARITY.md.
 */
enum TrackRule: string
{
    /** Track every message, even when the counterparty isn't a known CRM record. */
    case All = 'all';

    /** Track only when the counterparty matches a CRM record (default; Dynamics' common mode). */
    case KnownContacts = 'known_contacts';

    /** Don't auto-track this mailbox at all. */
    case None = 'none';

    public static function default(): self
    {
        return self::KnownContacts;
    }
}
