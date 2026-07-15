# SMOH Mail Tracker

Email-to-CRM tracking for **SMOH** — a multi-tenant SaaS that connects each client
org's mailboxes (**Outlook/M365** and **Gmail/Google Workspace**) to their own SMOH CRM
instance through **one shared Laravel backend**. Implements the architecture in
[`MASTER-PLAN.md`](./MASTER-PLAN.md).

> **Status:** Phase 1 (user-driven MVP) is built and tested end-to-end for the Outlook
> pillar and the backend brain; the Gmail contextual card and the Phase-2 zero-touch
> sync engine are scaffolded (endpoints, verification, tenant-context jobs) with the
> provider-API fetch marked `TODO(Phase 2)`. See [What's built](#whats-built).

## Architecture

One multi-tenant backend (the brain) + two thin clients. The clients only sign in, read
the current message, and render UI; **all** matching, dedup, logging, and background
sync live in the backend, which talks to each tenant's SMOH server-to-server (no browser
CORS, no SMOH credentials in any client).

```
Outlook add-in ─┐                        ┌─ Microsoft Graph (Phase 2 webhooks)
(Office.js/NAA) │   Laravel backend      │
                ├──►  (multi-tenant) ◄───┤
Gmail add-on ───┘   API · sync · queues  └─ Gmail API + Pub/Sub (Phase 2)
(CardService)              │
                           ▼
                   per-tenant SMOH (OData v4)
```

## Monorepo layout

```
packages/
  core/            Shared TS: SMOH OData contract, domain types, canonical dedup keys
  backend/         Laravel 13 — the multi-tenant brain (API + sync engine)   ← start here
  addin-outlook/   Office.js add-in (thin client): NAA/MSAL + OnMessageSend Smart Alert
  addin-gmail/     Google Workspace add-on — served BY the backend (alternate runtime)
MASTER-PLAN.md     The spec this implements
outlook.md         Superseded original draft (kept for history)
```

The Gmail add-on has **no separate codebase**: in the alternate runtime Google POSTs
event JSON to the backend, which returns CardService JSON (see
[`packages/backend`](./packages/backend/README.md) → Gmail endpoints).

## Quick start

Prereqs: PHP 8.3+, Composer, Node 20+. Redis is only needed to run Horizon/queues in
production — local dev and the test suite use the database/sync drivers.

```bash
# 1. Shared core (types + dedup keys) — build first; the add-in imports it.
npm install
npm run build:core && npm run test:core

# 2. Backend (the brain)
cd packages/backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed          # SQLite by default
php artisan test                    # 34 tests: SMOH client, auth, full E2E flow, webhooks
php artisan serve                   # http://127.0.0.1:8000

# 3. Outlook add-in (thin client)
cd ../addin-outlook
cp .env.example .env                # set BACKEND_BASE_URL + ENTRA_CLIENT_ID
npm run typecheck && npm run build
npm start                           # https://localhost:3000 — then sideload manifest.xml
```

## Local demo mode (no Azure, no SMOH)

To exercise the whole flow without an Entra app or a real SMOH instance, enable demo mode:
a **dev-login** endpoint replaces MSAL, and an **in-process fake SMOH** returns data.

- Backend `.env`: `SMOH_FAKE=true`, `MAIL_TRACKER_DEV_AUTH=true`, `QUEUE_CONNECTION=sync`.
- Add-in `.env`: `DEV_FAKE_AUTH=true`, `BACKEND_BASE_URL=https://localhost:3000`.

Then run the backend (`php artisan serve`, port 8000) and the add-in dev server
(`npm start`, which proxies `/api` → the backend). Clicking **Sign in** logs in as a
demo user; every address "matches" a stable fake contact, and logging/timeline work
against the in-memory fake CRM. Neither flag may be enabled in production.

**Try zero-touch sync locally** (Outlook/Graph, Phase 2): set `GRAPH_FAKE=true` in the
backend `.env`, then `php artisan graph:simulate --user=rep@demo.test`. It runs a fake
Graph notification through the real pipeline (fetch → match → log) and the inbound email
appears at `/dev/emails` with `source=sync`. No Graph app registration or public webhook
needed.

## What's built

| Area | Status |
| :--- | :--- |
| Shared dedup keys (Message-ID + synthetic), OData query builders | ✅ Tested; **byte-identical** in TS and PHP (golden vector) |
| Multi-tenancy (stancl single-DB), encrypted per-tenant SMOH creds | ✅ Tested |
| Provider OIDC verification (Entra + Google), `auth/exchange` → Sanctum | ✅ Tested with real signed JWTs |
| `contacts/match`, `activities/email`, `contacts/{id}/timeline` | ✅ Tested (full E2E) |
| Dedup ledger + async `LogEmailActivityJob` (backoff, 429 release, audit) | ✅ Tested |
| SMOH client (login cache, `$metadata` set resolution, contact/activity/timeline) | ✅ Tested |
| Outlook add-in: NAA/MSAL, task pane, `OnMessageSend` SoftBlock | ✅ Typecheck + build; needs a live Office host to run |
| Gmail contextual card (alternate runtime) + `userIdToken` verify | ✅ Tested; message fetch is `TODO(Phase 2)` |
| Zero-touch (Outlook/Graph): webhook + `clientState`, subscription create/renew, notification→fetch→ingest, lifecycle events, send-time reconciliation | ✅ Built + tested; fake-Graph demo via `graph:simulate` |
| Zero-touch (Gmail): Pub/Sub verify built; history fetch + ingest | ⬜ Deferred until the server is up |
| Delta catch-up reconciliation (missed lifecycle) | ⬜ Logged/flagged; not yet implemented |

## Corrections from the plan honored

The [`MASTER-PLAN`](./MASTER-PLAN.md) §9 corrections are reflected in code: XML manifest
(not unified), NAA + custom-API access token (never the id token / Graph), synthetic key
at send time (Message-ID is empty during `OnMessageSend`), and the ~7-day Graph
subscription lifetime. See per-package READMEs for detail.
