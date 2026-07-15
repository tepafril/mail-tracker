<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Support\DedupKey;

/** A single mailbox address (normalized) with an optional display name. */
final readonly class MailAddressData
{
    public function __construct(
        public string $address,
        public ?string $name = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            address: DedupKey::normalizeAddress((string) ($data['address'] ?? '')),
            name: isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
        );
    }
}
