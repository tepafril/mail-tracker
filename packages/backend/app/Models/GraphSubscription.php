<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A Microsoft Graph change-notification subscription (Phase 2).
 *
 * @property string $subscription_id
 * @property string $client_state
 * @property \Illuminate\Support\Carbon $expiration
 */
class GraphSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'subscription_id', 'resource', 'client_state', 'expiration',
    ];

    protected function casts(): array
    {
        return ['expiration' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
