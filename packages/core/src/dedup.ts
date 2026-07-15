/**
 * Canonical deduplication keys.
 *
 * These functions define the single source of truth for how mail is deduplicated
 * across the whole system. The Laravel backend has a byte-for-byte mirror in
 * `App\Support\DedupKey` (PHP). **If you change the canonicalization here, change
 * it there in the same commit** — the backend enforces `UNIQUE(tenant_id, message_id)`
 * and reconciles synthetic keys, so a drift between the two silently doubles-logs.
 *
 * Two keys exist:
 *  - {@link normalizeMessageId}: the *preferred* key. The RFC 5322 Message-ID is the
 *    same standards header on Outlook (`internetMessageId`) and Gmail (`Message-Id`).
 *  - {@link syntheticKey}: the *send-time fallback*, used only when no Message-ID is
 *    available yet (Outlook `OnMessageSend`). Reconciled to the real Message-ID later.
 */

/**
 * Normalize an RFC 5322 Message-ID to a stable dedup key.
 *
 * Providers wrap the id in angle brackets and may vary whitespace/case. We strip the
 * brackets and surrounding whitespace and lower-case the whole thing. Lower-casing the
 * local-part is technically stricter than RFC 5322 (local-parts are case-sensitive),
 * but no real-world MTA relies on Message-ID local-part case, and matching the two
 * providers reliably matters more than that theoretical distinction.
 *
 * @returns the normalized id, or `null` if the input is empty/whitespace.
 */
export function normalizeMessageId(raw: string | null | undefined): string | null {
  if (raw == null) return null;
  let id = raw.trim();
  if (id.startsWith('<')) id = id.slice(1);
  if (id.endsWith('>')) id = id.slice(0, -1);
  id = id.trim().toLowerCase();
  return id.length > 0 ? id : null;
}

/** Normalize a single email address for hashing/matching: trim + lower-case. */
export function normalizeAddress(addr: string): string {
  return addr.trim().toLowerCase();
}

/** Collapse internal whitespace runs to a single space and trim the ends. */
function collapseWhitespace(s: string): string {
  return s.replace(/\s+/g, ' ').trim();
}

/**
 * Inputs to {@link syntheticKey}. The tuple `(tenant, user, recipients, subject,
 * minute-bucket)` is what the master plan specifies.
 */
export interface SyntheticKeyInput {
  tenantId: string;
  userId: string;
  /** All recipients (To + Cc). Order-independent — we sort before hashing. */
  recipients: string[];
  subject: string;
  /** Send time in epoch milliseconds. Bucketed to the minute. */
  sentAtMs: number;
}

/**
 * Build the canonical string that {@link syntheticKey} hashes. Exported so tests
 * (and the PHP mirror) can assert on the exact pre-image without a digest.
 *
 * Format (LF-separated, no trailing newline):
 * ```
 * tenantId
 * userId
 * addr1,addr2,addr3     (normalized, de-duplicated, sorted)
 * collapsed-subject
 * minuteBucket          (floor(sentAtMs / 60000))
 * ```
 */
export function syntheticKeyPreimage(input: SyntheticKeyInput): string {
  const recipients = Array.from(
    new Set(input.recipients.map(normalizeAddress).filter((a) => a.length > 0)),
  ).sort();
  const minuteBucket = Math.floor(input.sentAtMs / 60_000);
  return [
    input.tenantId,
    input.userId,
    recipients.join(','),
    collapseWhitespace(input.subject),
    String(minuteBucket),
  ].join('\n');
}

/**
 * Compute the send-time idempotency key as a lowercase hex SHA-256 digest of
 * {@link syntheticKeyPreimage}. Async because it uses Web Crypto (browser + Node 20+).
 *
 * ACCEPTED LIMITATION (per MASTER-PLAN §7.2): two genuinely distinct messages with the
 * same (tenant, user, recipients, subject) sent within the same minute bucket collide,
 * so the second would be deduped. This is inherent to a deterministic send-time key that
 * must reconcile against the eventual RFC 5322 Message-ID; the body is deliberately
 * excluded to keep that reconciliation deterministic. The real Message-ID is the primary
 * key once available, bounding the collision window to sub-minute same-subject resends.
 */
export async function syntheticKey(input: SyntheticKeyInput): Promise<string> {
  return sha256Hex(syntheticKeyPreimage(input));
}

/**
 * Isomorphic SHA-256 → lowercase hex via the Web Crypto API (`crypto.subtle`), which is
 * present in browsers, the Office.js webview, and Node 20+ (our minimum engine). No
 * Node-specific `crypto` import, so this bundles cleanly for the add-ins.
 */
export async function sha256Hex(input: string): Promise<string> {
  const cryptoObj: Crypto | undefined = (globalThis as { crypto?: Crypto }).crypto;
  if (!cryptoObj?.subtle) {
    throw new Error('Web Crypto (crypto.subtle) is unavailable in this environment.');
  }
  const data = new TextEncoder().encode(input);
  const digest = await cryptoObj.subtle.digest('SHA-256', data);
  return bufferToHex(new Uint8Array(digest));
}

function bufferToHex(bytes: Uint8Array): string {
  let hex = '';
  for (const b of bytes) hex += b.toString(16).padStart(2, '0');
  return hex;
}
