<?php

declare(strict_types=1);

namespace App\Services\Smoh;

/**
 * Immutable per-tenant connection settings for a {@see SmohClient}. Built from a
 * Tenant row server-side; the credentials never leave the backend. Kept as a plain
 * value object (no Eloquent) so the client is unit-testable without a database.
 */
final readonly class SmohConfig
{
    /**
     * @param  string  $tenantId          Stable tenant key; namespaces cache entries.
     * @param  string  $baseUrl           SMOH root, e.g. https://acme.smoh.example (no trailing slash).
     * @param  string  $authUsername      Service-account username for POST /auth/login.
     * @param  string  $authPassword      Service-account password for POST /auth/login.
     * @param  string  $emailType         Fully-qualified OData type for email activities.
     * @param  string  $contactType       Fully-qualified OData type for contacts.
     * @param  list<string>  $contactEmailFields  Contact fields searched when matching.
     * @param  string|null  $emailActivitySet  Pre-resolved CRM.Email entity-set name, if known.
     * @param  string|null  $contactSet    Pre-resolved contact entity-set name, if known.
     */
    public function __construct(
        public string $tenantId,
        public string $baseUrl,
        public string $authUsername,
        public string $authPassword,
        public string $emailType = 'CRM.Email',
        public string $contactType = 'CRM.Contact',
        public array $contactEmailFields = ['email', 'email_business'],
        public ?string $emailActivitySet = null,
        public ?string $contactSet = null,
        // Lead/account matching (Dynamics-style: resolve recipients to contacts, then
        // leads, then accounts). Sets are resolved from $metadata when left null.
        public string $leadType = 'CRM.Lead',
        public string $accountType = 'CRM.Account',
        public array $leadEmailFields = ['email'],
        public array $accountEmailFields = ['primary_email'],
        public ?string $leadSet = null,
        public ?string $accountSet = null,
    ) {}

    /** SMOH root without a trailing slash. */
    public function base(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /** Cache-key prefix that isolates this tenant's cached token/metadata. */
    public function cachePrefix(): string
    {
        return 'smoh:'.sha1($this->tenantId.'|'.$this->base().'|'.$this->authUsername);
    }
}
