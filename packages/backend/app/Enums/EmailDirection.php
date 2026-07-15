<?php

declare(strict_types=1);

namespace App\Enums;

/** Direction of an email relative to the tenant's user. */
enum EmailDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
