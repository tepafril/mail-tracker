<?php

declare(strict_types=1);

namespace App\Support;

/**
 * OData v4 query construction for SMOH — PHP mirror of `packages/core/src/smoh/odata.ts`.
 * Keeping the two in lockstep means "how we query SMOH" is specified once and tested
 * with the same vectors on both sides.
 */
final class ODataQuery
{
    /** @var list<string> Contact fields searched when matching by email. */
    public const DEFAULT_CONTACT_EMAIL_FIELDS = ['email', 'email_business'];

    public const EMAIL_REGARDING_TYPE = 'CRM.Email';

    /** Escape a string for an OData literal by doubling single quotes (not wrapped). */
    public static function escapeString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /** Wrap a value as a quoted OData string literal. */
    public static function literal(string $value): string
    {
        return "'".self::escapeString($value)."'";
    }

    /**
     * `$filter` matching a contact by email across the given fields, e.g.
     * `(tolower(email) eq 'a@b.com' or tolower(email_business) eq 'a@b.com')`.
     *
     * @param  list<string>  $fields
     */
    public static function contactMatchFilter(string $email, array $fields = self::DEFAULT_CONTACT_EMAIL_FIELDS): string
    {
        // A misconfigured empty field list would yield the invalid filter "()" and break
        // every lookup — fall back to the defaults instead.
        if ($fields === []) {
            $fields = self::DEFAULT_CONTACT_EMAIL_FIELDS;
        }

        $value = self::literal(mb_strtolower(trim($email)));
        $clauses = array_map(static fn (string $f): string => "tolower({$f}) eq {$value}", $fields);

        return '('.implode(' or ', $clauses).')';
    }

    /**
     * OData query params for the contact-match lookup ($top=1).
     *
     * @param  list<string>  $fields
     * @return array<string, string|int>
     */
    public static function contactMatchParams(string $email, array $fields = self::DEFAULT_CONTACT_EMAIL_FIELDS): array
    {
        return [
            '$filter' => self::contactMatchFilter($email, $fields),
            '$select' => 'id',
            '$top' => 1,
        ];
    }

    /**
     * `$filter` matching records whose given fields contain the query (case-insensitive) —
     * used by the Set Regarding record search.
     *
     * @param  list<string>  $fields
     */
    public static function searchFilter(string $query, array $fields): string
    {
        if ($fields === []) {
            $fields = self::DEFAULT_CONTACT_EMAIL_FIELDS;
        }

        $value = self::escapeString(mb_strtolower(trim($query)));
        $clauses = array_map(static fn (string $f): string => "contains(tolower({$f}), '{$value}')", $fields);

        return '('.implode(' or ', $clauses).')';
    }

    /**
     * OData query params for a record search.
     *
     * @param  list<string>  $fields
     * @return array<string, string|int>
     */
    public static function searchParams(string $query, array $fields, int $top = 10): array
    {
        return [
            '$filter' => self::searchFilter($query, $fields),
            '$top' => $top,
        ];
    }

    /** `$filter` for a contact's email timeline. */
    public static function timelineFilter(string $contactId): string
    {
        return 'regarding_id eq '.$contactId.' and regarding_type eq '.self::literal(self::EMAIL_REGARDING_TYPE);
    }

    /**
     * OData query params for a contact's email timeline, newest first.
     *
     * @return array<string, string|int>
     */
    public static function timelineParams(string $contactId, int $top = 50): array
    {
        return [
            '$filter' => self::timelineFilter($contactId),
            '$orderby' => 'sent_at desc',
            '$top' => $top,
        ];
    }

    /**
     * Extract the new record key from an `OData-EntityId` header, e.g.
     * `https://x/odata/CRM.Email(<guid>)` -> `<guid>`. Returns null if unparseable.
     */
    public static function parseEntityIdHeader(?string $header): ?string
    {
        if ($header === null || $header === '') {
            return null;
        }

        if (! preg_match('/\(([^)]+)\)\s*$/', $header, $m)) {
            return null;
        }

        $key = trim($m[1]);
        $len = strlen($key);
        // Strip matching surrounding quotes. Use >= 1 (not >= 2) so a degenerate lone
        // quote key collapses to '' -> null, matching the TS mirror's slice(1,-1).
        if ($len >= 1
            && (($key[0] === "'" && $key[$len - 1] === "'") || ($key[0] === '"' && $key[$len - 1] === '"'))) {
            $key = substr($key, 1, -1);
        }

        return $key === '' ? null : $key;
    }
}
