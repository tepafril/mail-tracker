<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit record of a single track attempt (MASTER-PLAN §4.9). Not
 * tenant-scoped: it is written with an explicit tenant_id and read by operators for
 * compliance. `updated_at` is disabled — rows are never mutated.
 */
class EmailTrackAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'provider',
        'internet_message_id',
        'synthetic_key',
        'outcome',
        'smoh_status',
        'detail',
    ];
}
