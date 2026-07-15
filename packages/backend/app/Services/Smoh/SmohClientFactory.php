<?php

declare(strict_types=1);

namespace App\Services\Smoh;

use App\Models\Tenant;

/**
 * Builds a {@see SmohClient} for a given tenant. Registered as a singleton in the
 * container so callers depend on the factory, not on `new SmohClient(...)`, which keeps
 * the per-tenant config resolution in one place and the client easy to fake in tests.
 */
class SmohClientFactory
{
    /** @var array<string, SmohClient> memoized per tenant id within a request/job */
    private array $clients = [];

    public function for(Tenant $tenant): SmohClient
    {
        return $this->clients[$tenant->id] ??= $this->fromConfig($this->configFor($tenant));
    }

    /**
     * Build a client from a config. Returns the DEV-only {@see FakeSmohClient} when
     * `services.smoh.fake` is on (SMOH_FAKE=true) so the demo needs no real SMOH.
     */
    public function fromConfig(SmohConfig $config): SmohClient
    {
        return config('services.smoh.fake')
            ? new FakeSmohClient($config)
            : new SmohClient($config);
    }

    private function configFor(Tenant $tenant): SmohConfig
    {
        // In fake/demo mode the FakeSmohClient ignores credentials, so don't decrypt
        // them — this also avoids failing on rows encrypted under a rotated APP_KEY.
        if (config('services.smoh.fake')) {
            return new SmohConfig(
                tenantId: (string) $tenant->id,
                baseUrl: (string) $tenant->smoh_base_url,
                authUsername: '',
                authPassword: '',
            );
        }

        $config = $tenant->smohConfig();

        return new SmohConfig(
            tenantId: $config->tenantId,
            baseUrl: $config->baseUrl,
            authUsername: $config->authUsername,
            authPassword: $config->authPassword,
            emailType: (string) config('services.smoh.email_type', $config->emailType),
            contactType: (string) config('services.smoh.contact_type', $config->contactType),
            contactEmailFields: (array) config('services.smoh.contact_email_fields', $config->contactEmailFields),
            emailActivitySet: $config->emailActivitySet,
            contactSet: null,
        );
    }
}
