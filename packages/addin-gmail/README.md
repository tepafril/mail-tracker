# SMOH Mail Tracker — Gmail Workspace Add-on

**There is no separate code here by design.** In the Google Workspace **alternate
runtime** (MASTER-PLAN §6.1), Google POSTs event JSON to HTTPS endpoints on the Laravel
**backend**, which returns CardService (Card V1) JSON. Keeping all logic in one PHP stack
means no second Apps Script codebase.

## Where the code lives

In [`packages/backend`](../backend/README.md):

- `App\Http\Controllers\Gmail\GmailAddonController` — contextual + action endpoints.
- `App\Services\Gmail\CardService` — Card V1 JSON builder.
- `App\Services\Auth\GoogleAddonVerifier` — verifies the add-on `userIdToken`.
- Routes: `POST /api/gmail/addon/contextual`, `POST /api/gmail/actions/log`.

## Deployment (Marketplace SDK)

This package holds the **deployment descriptor** to register with the Google Workspace
Marketplace SDK: an HTTP deployment pointing `homepageTrigger` / `contextualTriggers` /
`universalActions` at the backend endpoints above, plus OAuth scopes.

See [`deployment.md`](./deployment.md) for the descriptor and the gating checklist
(OAuth brand verification, restricted-scope verification, CASA Tier 2 — MASTER-PLAN §6.5).

## On-send gap (by design)

Gmail has **no** client-side on-send interception (no `OnMessageSend` equivalent). The
contextual card is best-effort; outbound tracking is **server-side and eventually
consistent** via Gmail history sync (Phase 2). See §6.2.
