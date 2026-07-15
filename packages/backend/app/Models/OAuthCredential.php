<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MailProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's offline OAuth grant for the Phase-2 sync engine. Tokens are encrypted at
 * rest (AES-256 via APP_KEY) and only decrypted server-side to call Graph / Gmail.
 *
 * @property string|null $access_token
 * @property string|null $refresh_token
 */
class OAuthCredential extends Model
{
    protected $table = 'oauth_credentials';

    protected $fillable = ['user_id', 'provider', 'access_token', 'refresh_token', 'scopes', 'expires_at'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'provider' => MailProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
