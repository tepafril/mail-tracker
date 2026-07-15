<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Smoh\SmohConfig;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A client organization. One tenant == one SMOH instance + service credentials.
 *
 * Runs in stancl/tenancy SINGLE-DATABASE mode: this row lives in the central DB and
 * tenant-scoped models are isolated by a `tenant_id` global scope, not by switching
 * connections. The SMOH credentials are stored encrypted at rest and only ever
 * decrypted server-side to call SMOH.
 *
 * @property string $id
 * @property string|null $smoh_base_url
 * @property string|null $smoh_auth_username  encrypted at rest
 * @property string|null $smoh_auth_password  encrypted at rest
 * @property string|null $smoh_email_activity_set
 * @property bool $is_active
 */
class Tenant extends BaseTenant
{
    /** @use HasFactory<\Database\Factories\TenantFactory> */
    use HasFactory;

    /**
     * Columns that are real table columns rather than keys inside the virtual `data`
     * JSON column. Everything not listed here is transparently stored in `data`.
     *
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'smoh_base_url',
            'smoh_auth_username',
            'smoh_auth_password',
            'smoh_email_activity_set',
            'is_active',
            'content_retention_days',
        ];
    }

    /**
     * Days to retain stored email content for this tenant, falling back to the global
     * default. Null means "retain indefinitely" (no auto-purge).
     */
    public function effectiveRetentionDays(): ?int
    {
        $days = $this->content_retention_days ?? config('mail_tracker.retention_days');

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    /**
     * Never serialize the decrypted SMOH credentials (the `encrypted` cast would
     * otherwise emit plaintext in toArray()/toJson()). Defense-in-depth in case a
     * tenant model is ever returned/logged directly.
     *
     * @var list<string>
     */
    protected $hidden = ['smoh_auth_username', 'smoh_auth_password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'smoh_auth_username' => 'encrypted',
            'smoh_auth_password' => 'encrypted',
            'is_active' => 'boolean',
            'content_retention_days' => 'integer',
        ];
    }

    /** Users (federated identities) belonging to this tenant. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Build the immutable SMOH connection config for this tenant. Decrypts the
     * credentials only at the moment of use.
     */
    public function smohConfig(): SmohConfig
    {
        return new SmohConfig(
            tenantId: (string) $this->id,
            baseUrl: (string) $this->smoh_base_url,
            authUsername: (string) $this->smoh_auth_username,
            authPassword: (string) $this->smoh_auth_password,
            emailActivitySet: $this->smoh_email_activity_set,
        );
    }
}
