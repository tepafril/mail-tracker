<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A Gmail `users.watch` registration (Phase 2).
 *
 * @property string $topic
 * @property int|null $history_id
 * @property \Illuminate\Support\Carbon $expiration
 */
class GmailWatch extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'topic', 'history_id', 'expiration'];

    protected function casts(): array
    {
        return [
            'history_id' => 'integer',
            'expiration' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
