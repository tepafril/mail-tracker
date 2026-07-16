/**
 * Task pane controller (MASTER-PLAN §5). Handles interactive sign-in (which primes the
 * MSAL cache the OnMessageSend runtime relies on), shows whether the open message's
 * counterparty is a known SMOH contact, offers one-click logging, and renders the
 * contact's timeline.
 */
import type { EmailMessage } from '@smoh/mail-tracker-core';

import {
  authenticate,
  matchContact,
  logEmail,
  timeline,
  searchRecords,
  setRegarding,
  type ExchangeResult,
  type RecordCandidate,
} from '../api/backendClient';
import { isNaaSupported } from '../auth/naa';
import { readCurrentMessage, ownEmail } from '../office/message';

let session: ExchangeResult | undefined;
let currentMessage: EmailMessage | undefined;
let matchedContactId: string | null = null;
let loggedLedgerId: number | undefined;

const $ = (id: string): HTMLElement => {
  const el = document.getElementById(id);
  if (!el) throw new Error(`missing #${id}`);
  return el;
};

function setStatus(text: string, cls: '' | 'error' | 'ok' = ''): void {
  const status = $('status');
  status.textContent = text;
  status.className = `row ${cls}`.trim();
}

/** The address we'd match on: recipient for outbound, sender for inbound. */
function counterparty(message: EmailMessage): string | undefined {
  return message.direction === 'outbound' ? message.to[0]?.address : message.from.address;
}

async function onSignIn(): Promise<void> {
  try {
    setStatus('Signing in…');
    session = await authenticate('interactive');
    $('signin').hidden = true;
    $('app').hidden = false;
    $('who').textContent = `Signed in as ${session.user.email}`;
    await loadContext();
  } catch (e) {
    setStatus(`Sign-in failed: ${(e as Error).message}`, 'error');
  }
}

async function loadContext(): Promise<void> {
  if (!session) return;
  try {
    currentMessage = await readCurrentMessage(session.user.tenant_id, String(session.user.id));
    const email = counterparty(currentMessage);

    if (!email) {
      $('contact').textContent = 'No counterparty address on this message.';
      return;
    }

    setStatus('Looking up contact…');
    matchedContactId = await matchContact(session.token, email);

    if (matchedContactId) {
      $('contact').innerHTML = `Contact match: <span class="badge">${email}</span>`;
      ($('log-btn') as HTMLButtonElement).disabled = false;
      await renderTimeline(matchedContactId);
    } else {
      $('contact').innerHTML = `<span class="muted">No CRM contact for ${email}. You can still log it.</span>`;
      ($('log-btn') as HTMLButtonElement).disabled = false;
    }
    setStatus('');
  } catch (e) {
    setStatus(`Could not read message: ${(e as Error).message}`, 'error');
  }
}

async function renderTimeline(contactId: string): Promise<void> {
  if (!session) return;
  try {
    const items = await timeline(session.token, contactId);
    if (items.length === 0) {
      $('timeline').innerHTML = '<span class="muted">No prior logged emails.</span>';
      return;
    }
    const lis = items
      .slice(0, 10)
      .map((i) => `<li>${String(i.subject ?? '(no subject)')} — ${String(i.sent_at ?? '')}</li>`)
      .join('');
    $('timeline').innerHTML = `<div class="muted">Recent activity:</div><ul>${lis}</ul>`;
  } catch {
    /* timeline is best-effort; ignore render failures */
  }
}

async function onLog(): Promise<void> {
  if (!session || !currentMessage) return;
  try {
    ($('log-btn') as HTMLButtonElement).disabled = true;
    setStatus('Logging…');
    const result = await logEmail(session.token, currentMessage);
    setStatus(result.deduped ? 'Already logged.' : 'Logged to CRM.', 'ok');

    // The email is now a CRM activity — reveal Set Regarding so it can be linked to a record.
    loggedLedgerId = result.ledgerId;
    $('regarding').hidden = false;

    if (matchedContactId) await renderTimeline(matchedContactId);
  } catch (e) {
    setStatus(`Logging failed: ${(e as Error).message}`, 'error');
    ($('log-btn') as HTMLButtonElement).disabled = false;
  }
}

function setRegardingStatus(text: string, cls: '' | 'error' | 'ok' = ''): void {
  const el = $('regarding-status');
  el.textContent = text;
  el.className = `row ${cls}`.trim();
}

async function onRegardingSearch(): Promise<void> {
  if (!session) return;
  const query = ($('regarding-q') as HTMLInputElement).value.trim();
  if (query.length < 2) {
    setRegardingStatus('Type at least 2 characters.');
    return;
  }
  try {
    setRegardingStatus('Searching…');
    const records = await searchRecords(session.token, query);
    renderRecords(records);
    setRegardingStatus(records.length ? '' : 'No records found.');
  } catch (e) {
    setRegardingStatus(`Search failed: ${(e as Error).message}`, 'error');
  }
}

function renderRecords(records: RecordCandidate[]): void {
  const list = $('regarding-results');
  list.innerHTML = '';
  for (const record of records) {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'link';
    btn.textContent = `${record.label} · ${record.type.replace('CRM.', '')}`;
    btn.addEventListener('click', () => void onSetRegarding(record));
    li.appendChild(btn);
    list.appendChild(li);
  }
}

async function onSetRegarding(record: RecordCandidate): Promise<void> {
  if (!session || loggedLedgerId === undefined) return;
  try {
    setRegardingStatus(`Linking to ${record.label}…`);
    await setRegarding(session.token, loggedLedgerId, record.id, record.type);
    setRegardingStatus(`Regarding set: ${record.label}`, 'ok');
    $('regarding-results').innerHTML = '';
  } catch (e) {
    setRegardingStatus(`Set Regarding failed: ${(e as Error).message}`, 'error');
  }
}

Office.onReady((info) => {
  if (info.host !== Office.HostType.Outlook) return;

  if (!isNaaSupported()) {
    setStatus('This Outlook version does not support the required authentication. Please update Outlook.', 'error');
  }

  $('signin-btn').addEventListener('click', () => void onSignIn());
  $('log-btn').addEventListener('click', () => void onLog());
  $('regarding-search-btn').addEventListener('click', () => void onRegardingSearch());
  ($('regarding-q') as HTMLInputElement).addEventListener('keydown', (e) => {
    if ((e as KeyboardEvent).key === 'Enter') void onRegardingSearch();
  });

  // Hint the mailbox the pane is loaded against.
  try {
    $('who').textContent = `Mailbox: ${ownEmail()}`;
  } catch {
    /* not available until signed in on some hosts */
  }
});
