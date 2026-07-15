<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Models\EmailActivityLedger;

/** Outcome of {@see \App\Services\Email\EmailIngestionService::ingest()}. */
final readonly class IngestionResult
{
    public function __construct(
        public EmailActivityLedger $ledger,
        /** True when an existing ledger row was found (no new work dispatched). */
        public bool $deduped,
    ) {}
}
