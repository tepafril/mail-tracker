<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailDirection;
use App\Enums\LedgerStatus;
use App\Enums\MailProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One row per logical email per tenant — the dedup ledger. Tenant-scoped, so every
 * lookup is automatically constrained to the current tenant (enforcing the
 * UNIQUE(tenant_id, …) idempotency semantics per tenant).
 *
 * @property string $tenant_id
 * @property string|null $internet_message_id
 * @property string|null $synthetic_key
 * @property MailProvider $provider
 * @property EmailDirection $direction
 * @property LedgerStatus $status
 * @property string|null $contact_id
 * @property string|null $smoh_activity_id
 */
class EmailActivityLedger extends Model
{
    use BelongsToTenant;

    protected $table = 'email_activity_ledger';

    /**
     * PII content columns scrubbed on erasure/retention. The dedup keys
     * (internet_message_id, synthetic_key), contact_id, smoh_activity_id, and audit
     * fields are deliberately KEPT so scrubbing doesn't re-open the door to re-logging.
     *
     * @var list<string>
     */
    public const PII_COLUMNS = [
        'subject', 'from_address', 'to_recipients', 'cc_recipients', 'bcc_recipients', 'body', 'body_type',
    ];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'internet_message_id',
        'synthetic_key',
        'provider',
        'direction',
        'source',
        'contact_id',
        'regarding_type',
        'smoh_activity_id',
        'subject',
        'status',
        'last_error',
        'attempts',
        'logged_at',
        // Full email content (encrypted at rest — see casts).
        'from_address',
        'to_recipients',
        'cc_recipients',
        'bcc_recipients',
        'body',
        'body_type',
        'email_sent_at',
        'content_purged_at',
    ];

    /** Hide the decrypted PII content from default array/JSON serialization. */
    protected $hidden = ['from_address', 'to_recipients', 'cc_recipients', 'bcc_recipients', 'body'];

    protected function casts(): array
    {
        return [
            'provider' => MailProvider::class,
            'direction' => EmailDirection::class,
            'status' => LedgerStatus::class,
            'logged_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'content_purged_at' => 'datetime',
            // PII encrypted at rest (MASTER-PLAN §7.4).
            'from_address' => 'encrypted',
            'to_recipients' => 'encrypted:array',
            'cc_recipients' => 'encrypted:array',
            'bcc_recipients' => 'encrypted:array',
            'body' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
