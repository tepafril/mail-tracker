<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MailProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central (non-tenant-scoped) resolver: (provider, external org id) -> tenant. Read
 * during auth/exchange before tenancy is initialized. See the migration for details.
 *
 * @property string $tenant_id
 * @property MailProvider $provider
 * @property string $external_org_id
 */
class TenantIdentityMapping extends Model
{
    protected $fillable = ['tenant_id', 'provider', 'external_org_id'];

    protected function casts(): array
    {
        return ['provider' => MailProvider::class];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
