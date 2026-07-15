<?php

declare(strict_types=1);

namespace App\Services\Graph;

use RuntimeException;

/** A Microsoft Graph call failed. Carries the HTTP status (0 for transport errors). */
class GraphException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?string $body = null)
    {
        parent::__construct($message);
    }
}
