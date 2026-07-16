<?php

declare(strict_types=1);

namespace App\Services\Smoh;

/**
 * A CRM record matched to an email address: its id and its OData type
 * (e.g. CRM.Contact / CRM.Lead / CRM.Account). Used as the activity's "regarding".
 */
final readonly class RecipientMatch
{
    public function __construct(
        public string $id,
        public string $type,
    ) {}
}
