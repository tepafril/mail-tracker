/**
 * The SMOH client *contract* — the interface the backend's server-side `SmohClient`
 * fulfills. This lives in `core` so that the shape of "how the system talks to SMOH"
 * is documented in one place and the thin clients can share the types. The clients
 * never call SMOH directly (no CORS, no credentials in the browser); they call the
 * backend, which implements this contract per tenant.
 */

import type {
  ContactMatchResult,
  EmailActivityPayload,
  TimelineEntry,
} from '../types.js';

/** Per-tenant SMOH connection config, resolved server-side from the tenant row. */
export interface SmohTenantConfig {
  /** Base URL of the tenant's SMOH instance, e.g. `https://acme.smoh.example`. */
  baseUrl: string;
  /** Service-account credential used for `POST /auth/login`. Never leaves the server. */
  authUsername: string;
  authPassword: string;
  /**
   * Resolved `CRM.Email` entity-set name (from `$metadata`). Optional in config: if
   * absent, the client resolves and caches it on first use.
   */
  emailActivitySet?: string;
}

/**
 * The operations the backend performs against a single tenant's SMOH instance.
 * Implementations are responsible for JWT caching, `$metadata` resolution, retry/
 * backoff on `429`, and tenant isolation.
 */
export interface SmohClientContract {
  /** Obtain (and cache) the SMOH Bearer JWT via `POST /auth/login`. */
  login(): Promise<void>;

  /** Resolve the `CRM.Email` entity-set name from `{base}/odata/$metadata`. */
  resolveEmailActivitySet(): Promise<string>;

  /**
   * Find a contact by any of its email fields. Returns `{ contactId: null }` when
   * there is no match — matching is the backend's join key to `regarding_id`.
   */
  findContactByEmail(email: string): Promise<ContactMatchResult>;

  /**
   * Create a `CRM.Email` activity. Returns the new activity id parsed from the
   * `OData-EntityId` response header.
   */
  logEmailActivity(payload: EmailActivityPayload): Promise<{ activityId: string }>;

  /** Fetch a contact's email activities, newest first. */
  timeline(contactId: string, top?: number): Promise<TimelineEntry[]>;
}
