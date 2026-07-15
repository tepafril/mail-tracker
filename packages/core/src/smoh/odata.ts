/**
 * OData v4 query construction for SMOH. These builders are the shared spec that the
 * backend's PHP `SmohClient` mirrors, so that "how we query SMOH" is defined once.
 *
 * SMOH is metadata-driven: the `CRM.Email` *entity-set* name is not hard-coded, it is
 * resolved at runtime from `{base}/odata/$metadata`. Builders that touch that set take
 * the resolved name as an argument rather than assuming it.
 */

/** Fields on a SMOH contact that hold an email address, in match priority order. */
export const DEFAULT_CONTACT_EMAIL_FIELDS = ['email', 'email_business'] as const;

/** The `regarding_type` discriminator value for email activities. */
export const EMAIL_REGARDING_TYPE = 'CRM.Email';

/**
 * Escape a string for use as an OData v4 string literal. Per spec, a single quote
 * inside a literal is escaped by doubling it. The result is NOT wrapped in quotes.
 */
export function escapeODataString(value: string): string {
  return value.replace(/'/g, "''");
}

/** Wrap a value as a quoted OData string literal, escaping as needed. */
export function odataLiteral(value: string): string {
  return `'${escapeODataString(value)}'`;
}

/**
 * Build the `$filter` for matching a contact by email across the given fields.
 * Produces e.g. `(tolower(email) eq 'a@b.com' or tolower(email_business) eq 'a@b.com')`.
 * The value is lower-cased to pair with `tolower(field)`.
 */
export function contactMatchFilter(
  email: string,
  fields: readonly string[] = DEFAULT_CONTACT_EMAIL_FIELDS,
): string {
  // An empty field list would yield the invalid filter "()"; fall back to defaults.
  const effective = fields.length > 0 ? fields : DEFAULT_CONTACT_EMAIL_FIELDS;
  const value = odataLiteral(email.trim().toLowerCase());
  const clauses = effective.map((f) => `tolower(${f}) eq ${value}`);
  return `(${clauses.join(' or ')})`;
}

/** Query string (without leading `?`) for the contact-match lookup, `$top=1`. */
export function contactMatchQuery(
  email: string,
  fields?: readonly string[],
): string {
  const params = new URLSearchParams();
  params.set('$filter', contactMatchFilter(email, fields));
  params.set('$select', 'id');
  params.set('$top', '1');
  return params.toString();
}

/**
 * `$filter` for a contact's email timeline:
 * `regarding_id eq <guid> and regarding_type eq 'CRM.Email'`.
 * GUIDs are emitted as bare OData `Edm.Guid` literals (no quotes), which SMOH accepts.
 */
export function timelineFilter(contactId: string): string {
  return `regarding_id eq ${contactId} and regarding_type eq ${odataLiteral(EMAIL_REGARDING_TYPE)}`;
}

/** Query string (without leading `?`) for a contact's email timeline, newest first. */
export function timelineQuery(contactId: string, top = 50): string {
  const params = new URLSearchParams();
  params.set('$filter', timelineFilter(contactId));
  params.set('$orderby', 'sent_at desc');
  params.set('$top', String(top));
  return params.toString();
}

/**
 * Parse the new record id out of a SMOH create response's `OData-EntityId` header,
 * which looks like `{base}/odata/CRM.Email(<guid>)` or `.../Set('key')`.
 * Returns the raw key (unquoted) or `null` if it can't be parsed.
 */
export function parseEntityIdHeader(header: string | null | undefined): string | null {
  if (!header) return null;
  const match = header.match(/\(([^)]+)\)\s*$/);
  if (!match) return null;
  let key = match[1]!.trim();
  if (
    (key.startsWith("'") && key.endsWith("'")) ||
    (key.startsWith('"') && key.endsWith('"'))
  ) {
    key = key.slice(1, -1);
  }
  return key.length > 0 ? key : null;
}
