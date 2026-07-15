<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\MailProvider;

/**
 * Supplies the current signing keys (keyed by `kid`) for a provider's JWKS. Abstracted
 * so the network/caching concern is swappable and the verifier is unit-testable with a
 * locally generated keypair.
 */
interface JwksProvider
{
    /**
     * @return array<string, \Firebase\JWT\Key>
     */
    public function keys(MailProvider $provider): array;
}
