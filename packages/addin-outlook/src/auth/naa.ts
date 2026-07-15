/**
 * Nested App Authentication (NAA) with MSAL — the *current* recommended auth path for
 * Office add-ins (MASTER-PLAN §5.1). We acquire an access token for our OWN custom API
 * scope and send it to the backend; we never request Graph and never send the id token.
 *
 * Two token paths exist:
 *  - Task pane (interactive allowed): MSAL NAA, with a silent-then-popup fallback.
 *  - OnMessageSend event runtime (interactive BLOCKED): MSAL silent only — see
 *    {@link getBackendTokenSilent}. If that fails, the caller must SoftBlock and tell
 *    the user to open the task pane once to prime the cache.
 */
import {
  createNestablePublicClientApplication,
  type IPublicClientApplication,
  type AuthenticationResult,
} from '@azure/msal-browser';

import { API_SCOPE, ENTRA_CLIENT_ID } from '../config';

let pca: IPublicClientApplication | undefined;

/** True if the host advertises NAA support (not declarable in the XML manifest — §5.2). */
export function isNaaSupported(): boolean {
  return (
    typeof Office !== 'undefined' &&
    !!Office.context?.requirements?.isSetSupported &&
    Office.context.requirements.isSetSupported('NestedAppAuth', '1.1')
  );
}

const PLACEHOLDER_CLIENT_ID = /^0{8}-0{4}-0{4}-0{4}-0{12}$/;

async function getPca(): Promise<IPublicClientApplication> {
  // Fail fast with a clear message instead of MSAL hanging on an invalid client id.
  if (!ENTRA_CLIENT_ID || PLACEHOLDER_CLIENT_ID.test(ENTRA_CLIENT_ID)) {
    throw new Error(
      'Sign-in is not configured: set a real ENTRA_CLIENT_ID in packages/addin-outlook/.env ' +
        '(a multi-tenant Entra app id) and restart the dev server.',
    );
  }

  if (pca) return pca;
  pca = await createNestablePublicClientApplication({
    auth: {
      clientId: ENTRA_CLIENT_ID,
      // Origin-only redirect for NAA multi-hub brokering (§5.2).
      authority: 'https://login.microsoftonline.com/common',
    },
  });
  return pca;
}

const request: { scopes: string[] } = { scopes: [API_SCOPE] };

/** Interactive-capable acquisition for the task pane: silent first, then popup. */
export async function getBackendTokenInteractive(): Promise<string> {
  const app = await getPca();
  const account = app.getActiveAccount() ?? app.getAllAccounts()[0];

  let result: AuthenticationResult;
  try {
    if (!account) throw new Error('no-account');
    result = await app.acquireTokenSilent({ ...request, account });
  } catch {
    result = await app.acquireTokenPopup(request);
  }

  app.setActiveAccount(result.account);
  return result.accessToken;
}

/**
 * Silent-only acquisition for the OnMessageSend event runtime, where popups are blocked.
 *
 * We use MSAL NAA `acquireTokenSilent` exclusively — NOT `OfficeRuntime.auth.getAccessToken`.
 * The latter requires `<WebApplicationInfo>` (legacy SSO) in the manifest, which we do
 * NOT declare (legacy Exchange/SSO tokens are off tenant-wide per MASTER-PLAN §5.1), and
 * it would mint a token with a DIFFERENT audience than our custom API scope — sending two
 * incompatible token types to the same /auth/exchange endpoint. Consistency wins.
 *
 * Throws if there is no cached account (user must open the task pane once to sign in).
 */
export async function getBackendTokenSilent(): Promise<string> {
  const app = await getPca();
  const account = app.getActiveAccount() ?? app.getAllAccounts()[0];
  if (!account) {
    throw new Error('No cached account; open the task pane to sign in once.');
  }
  const result = await app.acquireTokenSilent({ ...request, account });
  return result.accessToken;
}
