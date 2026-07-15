<?php

declare(strict_types=1);

namespace App\Services\Graph;

/**
 * Builds a {@see GraphClient} for a client org (Entra tenant id), returning the DEV-only
 * {@see FakeGraphClient} when `services.graph.fake` is on. Registered as a singleton.
 */
class GraphClientFactory
{
    /** @var array<string, GraphClient> */
    private array $clients = [];

    public function forOrg(string $orgTenantId): GraphClient
    {
        return $this->clients[$orgTenantId] ??= config('services.graph.fake')
            ? new FakeGraphClient($orgTenantId)
            : new GraphClient($orgTenantId);
    }
}
