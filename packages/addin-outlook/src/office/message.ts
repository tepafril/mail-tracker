/**
 * Reads the current Outlook item into the canonical {@link EmailMessage} using Office.js
 * item APIs only — NO Microsoft Graph, so the add-in needs no Graph scopes or admin
 * consent (MASTER-PLAN §5.1). The synthetic dedup key is computed with the shared core
 * logic so it matches what the backend/PHP expects (§7.2).
 */
import {
  syntheticKey,
  normalizeMessageId,
  type EmailMessage,
  type MailAddress,
} from '@smoh/mail-tracker-core';

function toAddress(details: Office.EmailAddressDetails | undefined): MailAddress | null {
  if (!details?.emailAddress) return null;
  return { address: details.emailAddress, name: details.displayName || undefined };
}

function toAddresses(list: Office.EmailAddressDetails[] | undefined): MailAddress[] {
  return (list ?? []).map(toAddress).filter((a): a is MailAddress => a !== null);
}

/** Promisify an Office `getAsync`-style call. */
function getAsync<T>(fn: (cb: (r: Office.AsyncResult<T>) => void) => void): Promise<T> {
  return new Promise((resolve, reject) => {
    fn((result) => {
      if (result.status === Office.AsyncResultStatus.Succeeded) resolve(result.value);
      else reject(result.error);
    });
  });
}

/** The signed-in mailbox address (used to infer direction and the compose `from`). */
export function ownEmail(): string {
  return Office.context.mailbox.userProfile.emailAddress;
}

/**
 * Read-mode capture (task pane on an open message). The Message-ID is available here,
 * so it becomes the primary dedup key; the synthetic key is still computed as a
 * consistent secondary.
 */
export async function readCurrentMessage(tenantId: string, userId: string): Promise<EmailMessage> {
  const item = Office.context.mailbox.item as Office.MessageRead;

  const from = toAddress(item.from) ?? { address: ownEmail() };
  const to = toAddresses(item.to);
  const cc = toAddresses(item.cc);
  const subject = item.subject ?? '';
  const sentAtMs = item.dateTimeCreated ? new Date(item.dateTimeCreated).getTime() : Date.now();
  const outbound = from.address.toLowerCase() === ownEmail().toLowerCase();

  const body = await getAsync<string>((cb) => item.body.getAsync(Office.CoercionType.Html, cb));

  return {
    internetMessageId: normalizeMessageId(item.internetMessageId),
    syntheticKey: await syntheticKey({
      tenantId,
      userId,
      recipients: [...to, ...cc].map((a) => a.address),
      subject,
      sentAtMs,
    }),
    subject,
    body,
    bodyType: 'html',
    from,
    to,
    cc,
    sentAt: new Date(sentAtMs).toISOString(),
    direction: outbound ? 'outbound' : 'inbound',
    provider: 'outlook',
  };
}

/**
 * Send-time capture (OnMessageSend). The message has no Message-ID yet, so
 * `internetMessageId` is null and the synthetic key is the idempotency anchor; the
 * backend reconciles it to the real Message-ID once the sent item surfaces via sync.
 */
export async function buildComposeMessage(tenantId: string, userId: string): Promise<EmailMessage> {
  const item = Office.context.mailbox.item as Office.MessageCompose;

  const [to, cc, bcc, subject, body] = await Promise.all([
    getAsync<Office.EmailAddressDetails[]>((cb) => item.to.getAsync(cb)),
    getAsync<Office.EmailAddressDetails[]>((cb) => item.cc.getAsync(cb)),
    getAsync<Office.EmailAddressDetails[]>((cb) => item.bcc.getAsync(cb)),
    getAsync<string>((cb) => item.subject.getAsync(cb)),
    getAsync<string>((cb) => item.body.getAsync(Office.CoercionType.Html, cb)),
  ]);

  // Include Bcc so a Cc/Bcc-only send still has recipients (and a counterparty), and
  // so the synthetic dedup key covers every recipient.
  const recipients = [...toAddresses(to), ...toAddresses(cc), ...toAddresses(bcc)];
  const sentAtMs = Date.now();

  return {
    internetMessageId: null,
    syntheticKey: await syntheticKey({
      tenantId,
      userId,
      recipients: recipients.map((a) => a.address),
      subject,
      sentAtMs,
    }),
    subject,
    body,
    bodyType: 'html',
    from: { address: ownEmail(), name: Office.context.mailbox.userProfile.displayName },
    to: toAddresses(to),
    cc: toAddresses(cc),
    bcc: toAddresses(bcc),
    sentAt: new Date(sentAtMs).toISOString(),
    direction: 'outbound',
    provider: 'outlook',
  };
}
