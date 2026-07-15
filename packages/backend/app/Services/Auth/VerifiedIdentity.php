<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\MailProvider;

/**
 * The trustworthy identity extracted from a verified provider OIDC token.
 *
 * `subject` is the stable per-user id (Entra `oid` / Google `sub`). `orgId` is the
 * organization discriminator used to resolve the tenant (Entra `tid` / Google `hd`);
 * it may be null for consumer Google accounts.
 */
final readonly class VerifiedIdentity
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public MailProvider $provider,
        public string $subject,
        public ?string $orgId,
        public ?string $email,
        public ?string $name,
        public array $claims = [],
    ) {}
}
