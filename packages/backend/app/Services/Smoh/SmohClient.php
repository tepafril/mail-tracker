<?php

declare(strict_types=1);

namespace App\Services\Smoh;

use App\Support\ODataQuery;
use DOMDocument;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Per-tenant SMOH OData v4 client — the server-side port of the add-in's
 * `src/crm/crmClient.ts` (MASTER-PLAN §4.4). One instance talks to exactly one
 * tenant's SMOH instance; construct via {@see SmohClientFactory}.
 *
 * Responsibilities: cache the SMOH Bearer JWT, resolve entity-set names from
 * `$metadata`, match contacts, create CRM.Email activities, read timelines, and turn
 * SMOH's HTTP failures into typed exceptions (notably {@see SmohThrottleException} for
 * 429 so queued jobs can back off).
 */
class SmohClient
{
    /** Candidate JSON keys for the bearer token in the /auth/login response. */
    private const TOKEN_KEYS = ['access_token', 'token', 'jwt', 'accessToken', 'bearer', 'id_token'];

    public function __construct(private readonly SmohConfig $config) {}

    // ---- Auth -------------------------------------------------------------------

    /** Force a fresh login and cache the returned JWT. */
    public function login(): string
    {
        $response = Http::asJson()
            ->acceptJson()
            ->post($this->config->base().'/auth/login', [
                'username' => $this->config->authUsername,
                'password' => $this->config->authPassword,
            ]);

        if (! $response->successful()) {
            $this->fail($response, 'SMOH login failed');
        }

        $token = $this->extractToken($response->json());
        if ($token === null) {
            throw new SmohException('SMOH login response did not contain a bearer token.', $response->status(), $response->body());
        }

        Cache::put($this->tokenCacheKey(), $token, $this->tokenTtlSeconds($token));

        return $token;
    }

    /** Cached JWT, logging in on a miss. */
    public function token(): string
    {
        $cached = Cache::get($this->tokenCacheKey());

        return is_string($cached) && $cached !== '' ? $cached : $this->login();
    }

    // ---- Metadata / entity-set resolution --------------------------------------

    /** Resolved CRM.Email entity-set name (config override wins, else $metadata). */
    public function emailActivitySet(): string
    {
        return $this->config->emailActivitySet ?? $this->resolveEntitySet($this->config->emailType);
    }

    /** Resolved contact entity-set name. */
    public function contactSet(): string
    {
        return $this->config->contactSet ?? $this->resolveEntitySet($this->config->contactType);
    }

    /** Resolved lead entity-set name. */
    public function leadSet(): string
    {
        return $this->config->leadSet ?? $this->resolveEntitySet($this->config->leadType);
    }

    /** Resolved account entity-set name. */
    public function accountSet(): string
    {
        return $this->config->accountSet ?? $this->resolveEntitySet($this->config->accountType);
    }

    /**
     * Resolve the OData entity-set name for a fully-qualified type (e.g. 'CRM.Email')
     * from `{base}/odata/$metadata`, cached per tenant+type. Falls back to matching on
     * the type's local name if the exact FQN is not found.
     */
    public function resolveEntitySet(string $type): string
    {
        $cacheKey = $this->config->cachePrefix().':set:'.$type;

        /** @var string $set */
        $set = Cache::remember($cacheKey, now()->addHours(6), function () use ($type): string {
            $response = $this->request()->get($this->config->base().'/odata/$metadata');
            if (! $response->successful()) {
                $this->fail($response, 'Failed to fetch SMOH $metadata');
            }

            $map = $this->parseEntitySets($response->body());

            if (isset($map[$type])) {
                return $map[$type];
            }

            // Fallback: match by local name after the last '.'.
            $local = str_contains($type, '.') ? substr(strrchr($type, '.'), 1) : $type;
            foreach ($map as $fqType => $setName) {
                $candidate = str_contains($fqType, '.') ? substr(strrchr($fqType, '.'), 1) : $fqType;
                if ($candidate === $local) {
                    return $setName;
                }
            }

            throw new SmohException("Could not resolve OData entity set for type [{$type}] from \$metadata.");
        });

        return $set;
    }

    // ---- Domain operations ------------------------------------------------------

    /** Find a contact by email across the configured fields. Returns the GUID or null. */
    public function findContactByEmail(string $email): ?string
    {
        return $this->matchInSet($this->contactSet(), $email, $this->config->contactEmailFields, 'contact');
    }

    /**
     * Resolve an email to a CRM record, trying contact -> lead -> account (Dynamics-style
     * matching order). Returns the first match with its id and OData type, or null.
     */
    public function resolveRecipient(string $email): ?RecipientMatch
    {
        if (($id = $this->matchInSet($this->contactSet(), $email, $this->config->contactEmailFields, 'contact')) !== null) {
            return new RecipientMatch($id, $this->config->contactType);
        }

        if (($id = $this->matchInSet($this->leadSet(), $email, $this->config->leadEmailFields, 'lead')) !== null) {
            return new RecipientMatch($id, $this->config->leadType);
        }

        if (($id = $this->matchInSet($this->accountSet(), $email, $this->config->accountEmailFields, 'account')) !== null) {
            return new RecipientMatch($id, $this->config->accountType);
        }

        return null;
    }

    /**
     * Match an email against one entity set's email fields; returns the record id or null.
     *
     * @param  list<string>  $fields
     */
    private function matchInSet(string $set, string $email, array $fields, string $label): ?string
    {
        $response = $this->request()->get(
            $this->config->base().'/odata/'.$set,
            ODataQuery::contactMatchParams($email, $fields),
        );

        if (! $response->successful()) {
            $this->fail($response, "SMOH {$label} lookup failed");
        }

        $rows = $response->json('value');

        if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
            $id = $rows[0]['id'] ?? null;

            return is_scalar($id) ? (string) $id : null;
        }

        return null;
    }

    /**
     * Search CRM records (contacts, leads, accounts) whose name or email contains the
     * query. Returns candidates for the add-in's "Set Regarding" picker.
     *
     * @return list<array{id: string, type: string, label: string}>
     */
    public function searchRecords(string $query, int $perType = 5): array
    {
        $sets = [
            [$this->contactSet(), $this->config->contactType, ['first_name', 'last_name', 'email', 'email_business']],
            [$this->leadSet(), $this->config->leadType, ['name', 'email']],
            [$this->accountSet(), $this->config->accountType, ['name', 'primary_email', 'domain']],
        ];

        $results = [];
        foreach ($sets as [$set, $type, $fields]) {
            foreach ($this->searchInSet($set, $fields, $query, $perType) as $row) {
                $id = $row['id'] ?? null;
                if (is_scalar($id) && (string) $id !== '') {
                    $results[] = ['id' => (string) $id, 'type' => $type, 'label' => $this->labelFor($row)];
                }
            }
        }

        return $results;
    }

    /** Re-point (or set) an activity's regarding to a CRM record (Set Regarding). */
    public function setActivityRegarding(string $activityId, string $regardingId, string $regardingType): void
    {
        $response = $this->request()->patch(
            $this->config->base().'/odata/'.$this->emailActivitySet().'('.$activityId.')',
            ['regarding_id' => $regardingId, 'regarding_type' => $regardingType],
        );

        if (! $response->successful()) {
            $this->fail($response, 'SMOH set-regarding failed');
        }
    }

    /**
     * Create a CRM.Email activity and return the new record id, parsed from the
     * `OData-EntityId` response header (falling back to a body `id`).
     *
     * @param  array<string, mixed>  $payload  Canonical activity shape (regarding_id/type, subject, body, direction, sent_at).
     */
    public function logEmailActivity(array $payload): string
    {
        $response = $this->request()->post(
            $this->config->base().'/odata/'.$this->emailActivitySet(),
            $payload,
        );

        if (! $response->successful()) {
            $this->fail($response, 'SMOH activity create failed');
        }

        $id = ODataQuery::parseEntityIdHeader($response->header('OData-EntityId'))
            ?? (is_scalar($body = $response->json('id')) ? (string) $body : null);

        if ($id === null) {
            throw new SmohException('SMOH activity created but no id could be resolved (no OData-EntityId header).', $response->status(), $response->body());
        }

        return $id;
    }

    /**
     * A contact's email activities, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(string $contactId, int $top = 50): array
    {
        $response = $this->request()->get(
            $this->config->base().'/odata/'.$this->emailActivitySet(),
            ODataQuery::timelineParams($contactId, $top),
        );

        if (! $response->successful()) {
            $this->fail($response, 'SMOH timeline fetch failed');
        }

        $rows = $response->json('value');

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Run a `contains` search over one entity set's fields; returns the raw rows.
     *
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function searchInSet(string $set, array $fields, string $query, int $top): array
    {
        $response = $this->request()->get(
            $this->config->base().'/odata/'.$set,
            ODataQuery::searchParams($query, $fields, $top),
        );

        if (! $response->successful()) {
            $this->fail($response, "SMOH {$set} search failed");
        }

        $rows = $response->json('value');

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Build a human-readable label for a record from whatever name/email fields it has.
     *
     * @param  array<string, mixed>  $row
     */
    private function labelFor(array $row): string
    {
        $name = trim(implode(' ', array_filter(
            [$row['name'] ?? null, $row['first_name'] ?? null, $row['last_name'] ?? null],
            static fn ($v) => is_string($v) && $v !== '',
        )));

        $email = '';
        foreach (['email', 'primary_email', 'domain'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                $email = $row[$key];
                break;
            }
        }

        if ($name === '') {
            $name = $email !== '' ? $email : 'Record';
        }

        return $email !== '' && $email !== $name ? "{$name} <{$email}>" : $name;
    }

    // ---- Internals --------------------------------------------------------------

    /**
     * Authenticated request builder. On a 401 the cached token is dropped (see
     * {@see self::fail()}), so the *next* call re-authenticates; combined with the
     * exp-derived TTL and safety margin, mid-flight expiry is rare.
     */
    private function request(): PendingRequest
    {
        return Http::baseUrl($this->config->base())
            ->withToken($this->token())
            ->acceptJson()
            ->asJson();
    }

    /**
     * Parse `$metadata` EDMX into a map of fully-qualified EntityType => EntitySet name.
     *
     * @return array<string, string>
     */
    private function parseEntitySets(string $xml): array
    {
        $map = [];
        if (trim($xml) === '') {
            return $map;
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET blocks network fetches; PHP 8 disables external-entity loading by
        // default, so this is safe against XXE.
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new SmohException('Could not parse SMOH $metadata XML.');
        }

        foreach ($doc->getElementsByTagNameNS('*', 'EntitySet') as $set) {
            if (! $set instanceof \DOMElement) {
                continue;
            }
            $name = $set->getAttribute('Name');
            $type = $set->getAttribute('EntityType');
            if ($name !== '' && $type !== '') {
                $map[$type] = $name;
            }
        }

        return $map;
    }

    /** Turn a non-2xx response into the right typed exception. */
    private function fail(Response $response, string $context): never
    {
        if ($response->status() === 429) {
            throw new SmohThrottleException($this->retryAfterSeconds($response), $response->body());
        }

        if ($response->status() === 401) {
            // Token likely expired mid-flight; drop it so the next attempt re-logs in.
            Cache::forget($this->tokenCacheKey());
        }

        throw new SmohException("{$context} (HTTP {$response->status()}).", $response->status(), $response->body());
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');
        if ($header === null || $header === '') {
            return null;
        }

        // RFC 7231: Retry-After is either delay-seconds or an HTTP-date.
        if (is_numeric($header)) {
            return max(0, (int) $header);
        }

        $timestamp = strtotime($header);

        return $timestamp !== false ? max(0, $timestamp - time()) : null;
    }

    /**
     * @param  mixed  $json
     */
    private function extractToken($json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        foreach (self::TOKEN_KEYS as $key) {
            if (isset($json[$key]) && is_string($json[$key]) && $json[$key] !== '') {
                return $json[$key];
            }
        }

        // Some APIs nest the token, e.g. { data: { token: ... } }.
        if (isset($json['data']) && is_array($json['data'])) {
            foreach (self::TOKEN_KEYS as $key) {
                if (isset($json['data'][$key]) && is_string($json['data'][$key]) && $json['data'][$key] !== '') {
                    return $json['data'][$key];
                }
            }
        }

        return null;
    }

    /**
     * Cache TTL (seconds) for a token. When the JWT `exp` is unreadable we fall back to
     * a configured default; when it IS readable we cache only for the real remaining
     * life (minus a 60s margin, clamped) — never the long default, so a near-expired
     * token isn't cached far past its validity.
     */
    private function tokenTtlSeconds(string $token): int
    {
        $exp = $this->jwtExp($token);
        if ($exp === null) {
            return (int) config('services.smoh.token_ttl', 3000);
        }

        $ttl = $exp - time() - 60;

        return max(1, min($ttl, 86400));
    }

    /** Best-effort read of a JWT `exp` claim without verifying the signature. */
    private function jwtExp(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            return null;
        }

        $claims = json_decode($payload, true);

        return is_array($claims) && isset($claims['exp']) && is_numeric($claims['exp'])
            ? (int) $claims['exp']
            : null;
    }

    private function tokenCacheKey(): string
    {
        return $this->config->cachePrefix().':token';
    }
}
