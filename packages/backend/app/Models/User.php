<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MailProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A federated mailbox user of one tenant. There is no password — identity is proven by
 * a provider OIDC token at `auth/exchange`, after which the user holds a first-party
 * Sanctum token. Tenant-scoped via {@see BelongsToTenant} (single-database mode): the
 * global scope filters every query to the current tenant and auto-fills `tenant_id`.
 *
 * @property int $id
 * @property string $tenant_id
 * @property MailProvider $provider
 * @property string|null $entra_oid
 * @property string|null $entra_tid
 * @property string|null $google_sub
 * @property string $email
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use BelongsToTenant;
    use HasApiTokens;
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'provider',
        'entra_oid',
        'entra_tid',
        'google_sub',
        'email',
        'name',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => MailProvider::class,
            'last_seen_at' => 'datetime',
        ];
    }

    public function oauthCredentials(): HasMany
    {
        return $this->hasMany(OAuthCredential::class);
    }
}
