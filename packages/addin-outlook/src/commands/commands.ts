/**
 * Event-runtime entry point. Hosts the `OnMessageSend` Smart Alert handler
 * (MASTER-PLAN §5.3). Interactive auth is BLOCKED here, so we authenticate silently;
 * if that or the backend call fails we SoftBlock and tell the user to open the task
 * pane once to sign in (which primes the MSAL cache).
 *
 * This file is bundled to a single self-contained `commands.js` (see webpack config).
 */
import { authenticate, logEmail } from '../api/backendClient';
import { buildComposeMessage } from '../office/message';

/**
 * Hard cap on the whole capture chain. Without it, a stalled backend leaves the user
 * stuck behind the Send spinner until the OS-level TCP timeout (tens of seconds). On
 * timeout we fall into the SoftBlock path so `event.completed` is always called promptly.
 */
const SEND_HANDLER_TIMEOUT_MS = 12_000;

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Timed out logging to the CRM.')), ms);
    promise.then(
      (value) => {
        clearTimeout(timer);
        resolve(value);
      },
      (error) => {
        clearTimeout(timer);
        reject(error);
      },
    );
  });
}

/**
 * OnMessageSend handler. Registered by the manifest against this function name.
 * Must ALWAYS call `event.completed(...)` exactly once, in bounded time.
 */
async function onMessageSendHandler(event: Office.AddinCommands.Event): Promise<void> {
  try {
    await withTimeout(
      (async () => {
        // Silent-only: popups are blocked in the send event runtime.
        const session = await authenticate('silent');
        const message = await buildComposeMessage(session.user.tenant_id, String(session.user.id));
        await logEmail(session.token, message);
      })(),
      SEND_HANDLER_TIMEOUT_MS,
    );

    // Logged (or accepted for async logging) — let the send proceed.
    event.completed({ allowEvent: true });
  } catch (error) {
    // SoftBlock (Marketplace-eligible): the user can still send, but is nudged to sign in.
    const message =
      error instanceof Error && error.message.includes('open the task pane')
        ? error.message
        : 'Could not log this email to the CRM. Open the Mail Tracker task pane to sign in, then send again.';

    // The manifest declares SendMode="SoftBlock", so allowEvent:false shows the message
    // with a "Send Anyway" option — never a hard block (§5.4). `errorMessage` may not be
    // present in older @types/office-js, so widen the options type.
    event.completed({
      allowEvent: false,
      errorMessage: message,
    } as Office.AddinCommands.EventCompletedOptions & { errorMessage?: string });
  }
}

// Register the handler with the event runtime.
Office.onReady(() => {
  // `Office.actions.associate` binds the manifest's action id to the handler function.
  Office.actions.associate('onMessageSendHandler', onMessageSendHandler);
});
