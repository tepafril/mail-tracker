<?php

declare(strict_types=1);

namespace App\Services\Smoh;

use RuntimeException;

/** A SMOH call failed. Carries the HTTP status (0 if none / transport error). */
class SmohException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $body = null,
    ) {
        parent::__construct($message);
    }
}
