/**
 * @smoh/mail-tracker-core — the shared contract for SMOH mail tracking.
 *
 * Import surface for the thin clients (Outlook add-in, Gmail add-on). The Laravel
 * backend does not import this package (it is PHP), but it mirrors the dedup keys and
 * OData builders exactly — see the notes in `dedup.ts` and `smoh/odata.ts`.
 */

export * from './types.js';
export * from './dedup.js';
export * from './smoh/odata.js';
export * from './smoh/contract.js';
