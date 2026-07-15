<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\MailProvider;

/**
 * Verifies a Gmail Workspace Add-on request in the alternate runtime (MASTER-PLAN §6.3).
 * Google puts the Google-signed `userIdToken` in the event's `authorizationEventObject`;
 * verifying its signature both identifies the user (stable `sub`) AND proves the request
 * genuinely came from Google. (`systemIdToken` verification can be layered on for extra
 * defense-in-depth once the deployment id is known.)
 */
class GoogleAddonVerifier
{
    public function __construct(private readonly TokenVerifier $verifier) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function verify(array $event): VerifiedIdentity
    {
        $token = data_get($event, 'authorizationEventObject.userIdToken');

        if (! is_string($token) || $token === '') {
            throw new TokenVerificationException('Add-on event missing userIdToken.');
        }

        return $this->verifier->verify(MailProvider::Gmail, $token);
    }
}
