# SMOH Mail Tracker — Outlook add-in (thin client)

Office.js add-in that logs Outlook email to the SMOH CRM via the Laravel backend.
Implements [`MASTER-PLAN`](../../MASTER-PLAN.md) §5. It reads mail with **Office.js item
APIs only** (no Microsoft Graph → no Graph scopes, no admin consent) and authenticates
with **Nested App Authentication (NAA)**.

## What it does

- **Task pane** (`ShowTaskpane`): interactive sign-in (primes the MSAL cache), shows
  whether the open message's counterparty is a known SMOH contact, one-click **Log to
  CRM**, and the contact's timeline.
- **`OnMessageSend` Smart Alert** (`SoftBlock`): on send, silently authenticates, builds
  the message (synthetic dedup key — there is no Message-ID yet at send time), and POSTs
  it to the backend. If silent auth or the network call fails it **SoftBlocks** with a
  prompt to open the task pane and sign in once (never a hard block → Marketplace-eligible).

## Auth (NAA, not the legacy flow)

`src/auth/naa.ts` uses `@azure/msal-browser` `createNestablePublicClientApplication` and
requests **only** the custom API scope `api://<app-id>/access_as_user` — never Graph,
never the id token. The event runtime (OnMessageSend) can't show popups, so it acquires
the token silently (`OfficeRuntime.auth.getAccessToken`, MSAL silent fallback).

## Build & run

```bash
cp .env.example .env      # set BACKEND_BASE_URL, ENTRA_CLIENT_ID (+ API_SCOPE if custom)
npm run typecheck
npm run build             # → dist/ (taskpane.html, taskpane.js, commands.js, assets/)
npm start                 # https://localhost:3000 dev server
```

Config is injected at build time by webpack `DefinePlugin` (see `src/config.ts`).

## Sideload / distribute

1. Update `manifest.xml`: replace the `Id` GUID (your Entra multi-tenant app id) and the
   `https://localhost:3000` URLs with your hosting origin.
2. Sideload `manifest.xml` in Outlook (or deploy via Microsoft 365 Admin → Integrated
   Apps for a pilot). Requires **Mailbox requirement set 1.15**.
3. Entra app registration: multi-tenant (`AzureADMultipleOrgs`), an SPA
   `brk-multihub://<origin>` redirect (origin only), Verified Publisher (§5.2).

For an unrestricted Marketplace listing (auto-launch on install), pursue Microsoft 365
Certification per §5.4.

## Notes

- `@azure/msal-browser` is pinned to `^4` here (NAA / `createNestablePublicClientApplication`
  is supported since 3.x). The plan targets `^5`; bump when adopting it.
- Placeholder icons in `assets/` are generated stand-ins — replace before publishing.
