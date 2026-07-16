/**
 * Thin HTTP client for the Laravel backend. The add-in never talks to SMOH or Graph
 * directly — it acquires an Entra access token (NAA), exchanges it for a first-party
 * Sanctum token, and then calls the backend, which does all matching/logging
 * server-to-server (MASTER-PLAN §3, §4.5).
 */
import type { EmailMessage } from '@smoh/mail-tracker-core';

import { API_BASE, DEV_FAKE_AUTH } from '../config';
import { getBackendTokenInteractive, getBackendTokenSilent } from '../auth/naa';

export interface ExchangeResult {
  token: string;
  abilities: string[];
  user: { id: number; email: string; tenant_id: string };
}

export interface LogResult {
  ledgerId: number;
  status: string;
  deduped: boolean;
  smohActivityId: string | null;
}

async function json<T>(response: Response): Promise<T> {
  if (!response.ok) {
    const body = await response.text().catch(() => '');
    throw new Error(`Backend ${response.status}: ${body.slice(0, 300)}`);
  }
  return (await response.json()) as T;
}

/** Exchange an Entra access token for a first-party Sanctum token. */
export async function exchange(entraToken: string): Promise<ExchangeResult> {
  const response = await fetch(`${API_BASE}/auth/exchange`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ provider: 'outlook', token: entraToken }),
  });
  return json<ExchangeResult>(response);
}

/** DEV/DEMO ONLY: mint a token via the backend's dev-login (no Entra). */
async function devLogin(): Promise<ExchangeResult> {
  const response = await fetch(`${API_BASE}/auth/dev-login`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
  });
  return json<ExchangeResult>(response);
}

/**
 * Full sign-in: NAA -> exchange. `mode: 'silent'` is mandatory inside OnMessageSend
 * (interactive popups are blocked there); the task pane uses `'interactive'`.
 *
 * In DEV_FAKE_AUTH mode we bypass Entra entirely and use the backend dev-login.
 */
export async function authenticate(mode: 'silent' | 'interactive'): Promise<ExchangeResult> {
  if (DEV_FAKE_AUTH) {
    return devLogin();
  }
  const entraToken = mode === 'silent' ? await getBackendTokenSilent() : await getBackendTokenInteractive();
  return exchange(entraToken);
}

function authHeaders(sanctumToken: string): Record<string, string> {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    Authorization: `Bearer ${sanctumToken}`,
  };
}

/** Resolve an email address to a SMOH contact id (or null). */
export async function matchContact(sanctumToken: string, email: string): Promise<string | null> {
  const response = await fetch(`${API_BASE}/contacts/match`, {
    method: 'POST',
    headers: authHeaders(sanctumToken),
    body: JSON.stringify({ email }),
  });
  const result = await json<{ contactId: string | null }>(response);
  return result.contactId;
}

/** Log a captured email. Returns the ledger outcome (202 accepted or 200 deduped). */
export async function logEmail(sanctumToken: string, message: EmailMessage): Promise<LogResult> {
  const response = await fetch(`${API_BASE}/activities/email`, {
    method: 'POST',
    headers: authHeaders(sanctumToken),
    body: JSON.stringify(message),
  });
  return json<LogResult>(response);
}

export interface RecordCandidate {
  id: string;
  type: string; // e.g. 'CRM.Contact' | 'CRM.Lead' | 'CRM.Account'
  label: string;
}

/** Search CRM records (contacts/leads/accounts) to pick a "Set Regarding" target. */
export async function searchRecords(sanctumToken: string, query: string): Promise<RecordCandidate[]> {
  const response = await fetch(`${API_BASE}/records?q=${encodeURIComponent(query)}`, {
    headers: authHeaders(sanctumToken),
  });
  const result = await json<{ records: RecordCandidate[] }>(response);
  return result.records;
}

/** Link a logged email (ledger row) to a chosen CRM record. */
export async function setRegarding(
  sanctumToken: string,
  ledgerId: number,
  regardingId: string,
  regardingType: string,
): Promise<void> {
  const response = await fetch(`${API_BASE}/activities/${ledgerId}/regarding`, {
    method: 'POST',
    headers: authHeaders(sanctumToken),
    body: JSON.stringify({ regarding_id: regardingId, regarding_type: regardingType }),
  });
  await json<{ id: number }>(response);
}

/** Fetch a contact's logged-email timeline. */
export async function timeline(
  sanctumToken: string,
  contactId: string,
): Promise<Array<Record<string, unknown>>> {
  const response = await fetch(`${API_BASE}/contacts/${encodeURIComponent(contactId)}/timeline`, {
    headers: authHeaders(sanctumToken),
  });
  const result = await json<{ items: Array<Record<string, unknown>> }>(response);
  return result.items;
}
