/**
 * Domain types shared across the mail-tracker thin clients and mirrored by the
 * Laravel backend. Kept intentionally provider-agnostic: an Outlook add-in and a
 * Gmail add-on both produce the same {@link EmailMessage} shape, and the backend
 * writes the same canonical activity to every tenant's SMOH instance.
 */

/** Direction of an email relative to the tenant's user. */
export type EmailDirection = 'inbound' | 'outbound';

/** The mail platform a message originated from. */
export type MailProvider = 'outlook' | 'gmail';

/** An email address with an optional display name (RFC 5322 mailbox). */
export interface MailAddress {
  /** Normalized, lower-cased address. Never contains display name or angle brackets. */
  address: string;
  /** Display name as presented by the provider, if any. */
  name?: string;
}

/**
 * A single email as captured by a thin client, before the backend matches it to a
 * SMOH contact. This is the request body of `POST /api/v1/activities/email`.
 */
export interface EmailMessage {
  /**
   * Normalized RFC 5322 Message-ID (see {@link normalizeMessageId}). Present for
   * read/received mail on both providers. **Empty at Outlook `OnMessageSend` time**
   * (the message has not been assigned an id yet) — in that case rely on
   * {@link EmailMessage.syntheticKey} and let the backend reconcile later.
   */
  internetMessageId: string | null;
  /**
   * Send-time idempotency fallback (see {@link syntheticKey}). Always set by the
   * client; the backend reconciles it to the real Message-ID once the sent item
   * surfaces via background sync.
   */
  syntheticKey: string;
  subject: string;
  /** Sanitized body (client strips scripts/pixels; backend sanitizes again). */
  body: string;
  /** Whether {@link EmailMessage.body} is `'html'` or `'text'`. */
  bodyType: 'html' | 'text';
  from: MailAddress;
  to: MailAddress[];
  cc?: MailAddress[];
  bcc?: MailAddress[];
  /** ISO-8601 timestamp of send/receipt. */
  sentAt: string;
  direction: EmailDirection;
  provider: MailProvider;
}

/** A SMOH contact, projected to the fields we query. `id` is a GUID string. */
export interface SmohContact {
  id: string;
  email?: string | null;
  email_business?: string | null;
  email_personal?: string | null;
}

/** Result of matching an email address to a SMOH contact. */
export interface ContactMatchResult {
  contactId: string | null;
}

/**
 * The canonical activity the backend writes to SMOH. `regarding_type` is always
 * the resolved `CRM.Email` type; `regarding_id` is the matched contact GUID.
 */
export interface EmailActivityPayload {
  regarding_id: string;
  regarding_type: string;
  subject: string;
  body: string;
  direction: EmailDirection;
  sent_at: string;
}

/** A logged email as returned by the contact timeline endpoint. */
export interface TimelineEntry {
  id: string;
  subject: string;
  direction: EmailDirection;
  sent_at: string;
}
