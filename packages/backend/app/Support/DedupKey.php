<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical deduplication keys — PHP mirror of `packages/core/src/dedup.ts`.
 *
 * The thin clients (browser) compute the send-time synthetic key with the TypeScript
 * implementation; this backend recomputes/validates it and normalizes Message-IDs
 * arriving from Graph and Gmail. The two implementations MUST produce identical
 * output: the canonical pre-image string and the SHA-256 hex digest are the contract.
 * If you change canonicalization here, change it in `dedup.ts` in the same commit.
 *
 * @see \Tests\Unit\DedupKeyTest for the vectors shared with the TS test-suite.
 */
final class DedupKey
{
    /**
     * Normalize an RFC 5322 Message-ID to a stable dedup key: strip angle brackets
     * and surrounding whitespace, then lower-case. Returns null for empty input.
     */
    public static function normalizeMessageId(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $id = trim($raw);

        if (str_starts_with($id, '<')) {
            $id = substr($id, 1);
        }
        if (str_ends_with($id, '>')) {
            $id = substr($id, 0, -1);
        }

        $id = mb_strtolower(trim($id));

        return $id === '' ? null : $id;
    }

    /** Normalize a single email address for hashing/matching: trim + lower-case. */
    public static function normalizeAddress(string $addr): string
    {
        return mb_strtolower(trim($addr));
    }

    /**
     * Build the canonical pre-image hashed by {@see self::synthetic()}. Exposed so
     * tests can assert on the exact string without a digest. LF-separated, no trailing
     * newline: tenantId, userId, sorted-de-duped-recipients (comma-joined), collapsed
     * subject, minute-bucket.
     *
     * @param  list<string>  $recipients  All recipients (To + Cc); order-independent.
     * @param  int  $sentAtMs  Send time in epoch milliseconds.
     */
    public static function syntheticPreimage(
        string $tenantId,
        string $userId,
        array $recipients,
        string $subject,
        int $sentAtMs,
    ): string {
        $normalized = [];
        foreach ($recipients as $r) {
            $addr = self::normalizeAddress($r);
            if ($addr !== '') {
                $normalized[$addr] = true; // de-dupe by key
            }
        }
        $sorted = array_keys($normalized);
        // SORT_STRING = byte-wise (code-point) order, matching JS default sort() for all
        // BMP addresses. ACCEPTED nuance: JS sorts by UTF-16 code unit, so an address
        // containing a supplementary-plane (astral) char could order differently vs a
        // high-BMP char — practically impossible in real email addresses, which MTAs
        // reject, so left as-is rather than emulating UTF-16 ordering in PHP.
        sort($sorted, SORT_STRING);

        // floor division (round toward -inf) to match JS Math.floor for negative epochs;
        // intdiv() truncates toward zero and would diverge for sentAtMs < 0.
        $minuteBucket = (int) floor($sentAtMs / 60_000);

        return implode("\n", [
            $tenantId,
            $userId,
            implode(',', $sorted),
            self::collapseWhitespace($subject),
            (string) $minuteBucket,
        ]);
    }

    /**
     * Send-time idempotency key: lowercase hex SHA-256 of {@see self::syntheticPreimage()}.
     *
     * @param  list<string>  $recipients
     */
    public static function synthetic(
        string $tenantId,
        string $userId,
        array $recipients,
        string $subject,
        int $sentAtMs,
    ): string {
        return hash('sha256', self::syntheticPreimage($tenantId, $userId, $recipients, $subject, $sentAtMs));
    }

    /**
     * Collapse internal whitespace runs to a single space and trim the ends. The class
     * includes U+FEFF (BOM / zero-width no-break space) explicitly: JS `\s` matches it
     * but PCRE `\s` (Unicode White_Space) does not, and the TS mirror relies on it — so
     * we add it here to keep the pre-images byte-identical.
     */
    private static function collapseWhitespace(string $s): string
    {
        return trim((string) preg_replace('/[\s\x{FEFF}]+/u', ' ', $s));
    }
}
