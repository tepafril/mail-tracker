<?php

declare(strict_types=1);

namespace App\Enums;

/** The mail platform a user / message belongs to. */
enum MailProvider: string
{
    case Outlook = 'outlook';
    case Gmail = 'gmail';

    /** OIDC issuer family, used to pick a token verifier. */
    public function isMicrosoft(): bool
    {
        return $this === self::Outlook;
    }
}
