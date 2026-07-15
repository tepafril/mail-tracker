# SMOH Mail Tracker — Backend (the brain)

Laravel 13 multi-tenant control plane. Verifies provider tokens, maps users to tenants,
talks to each tenant's SMOH instance server-to-server, dedups + logs email, and (Phase 2)
runs the zero-touch sync engine. Implements [`MASTER-PLAN`](../../MASTER-PLAN.md) §4, §7.

## Stack

Laravel 13 · `stancl/tenancy` (single-DB) · `laravel/sanctum` · `laravel/horizon` ·
`firebase/php-jwt` · Guzzle · Redis + Octane in production.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed     # SQLite by default; seeds one demo tenant + Outlook user
php artisan test               # 34 tests, all green
php artisan serve
```

Public HTTPS is required in production (webhooks). Run queues with Horizon
(`php artisan horizon`) backed by Redis; locally the `database`/`sync` queue works.

## Configuration

All custom keys are documented in [`.env.example`](./.env.example): `SMOH_*` (CRM client),
`ENTRA_*` / `GOOGLE_*` (OIDC verification), `MAIL_TRACKER_*` (blacklist, body storage),
and `DEMO_*` (local seed). Per-tenant SMOH base URL + service credentials live
**encrypted** on the `tenants` row, never in env.

## Multi-tenancy (single-database mode)

`config/tenancy.php` is set to single-DB: tenants share one database and are isolated by
a `tenant_id` global scope (`Stancl\...\BelongsToTenant`), not by switching connections.
The only bootstrapper kept is `QueueTenancyBootstrapper`. Tenancy is initialized from the
**authenticated user** (`App\Http\Middleware\InitializeTenancyForUser`), because our
tenant is discovered from the OIDC token at `auth/exchange`, not from a domain.

## API

Base: `/api/v1`.

| Method + path | Auth | Purpose |
| :--- | :--- | :--- |
| `POST /auth/exchange` | public | `{provider, token}` → first-party Sanctum token |
| `GET  /me` | sanctum | current user + tenant |
| `POST /contacts/match` | `contacts:read` | `{email}` → `{contactId|null}` |
| `POST /activities/email` | `activities:write` | dedup on ledger, dispatch `LogEmailActivityJob` |
| `GET  /contacts/{id}/timeline` | `contacts:read` | contact's logged emails, newest first |

Zero-touch + Gmail (public, verified per request):

| Method + path | Purpose |
| :--- | :--- |
| `POST /api/webhooks/graph/notifications` | Graph validation handshake + notifications (verify `clientState`) |
| `POST /api/webhooks/gmail/push` | Gmail Pub/Sub push (verify OIDC token) |
| `POST /api/gmail/addon/contextual` | Gmail add-on contextual card (verify `userIdToken`) |
| `POST /api/gmail/actions/log` | Gmail "Log to CRM" button action |

## Key modules

- `App\Services\Smoh\SmohClient` — per-tenant OData client (port of the add-in's
  `crmClient.ts`): login cache, `$metadata` entity-set resolution, contact match,
  `CRM.Email` create (id from `OData-EntityId`), timeline, 429 → `SmohThrottleException`.
- `App\Services\Auth\TokenVerifier` — Entra/Google OIDC: signature (JWKS) + `iss`/`aud`.
- `App\Support\DedupKey` / `App\Support\ODataQuery` — **PHP mirrors** of the shared core;
  the golden dedup vector is asserted identically in both suites.
- `App\Jobs\LogEmailActivityJob` — `ShouldBeUnique`, `backoff=[10,30,60,300,600]`,
  `tries=8`, releases on 429, writes an append-only audit row.

## Open items (need product/SMOH input)

The exact SMOH `CRM.Email` property names for the activity payload are a best-guess
mapping in `EmailMessageData::toSmohActivity()` — confirm against SMOH `$metadata`
(MASTER-PLAN §10).

The full email (from, to/cc/bcc, sanitized body, body_type, sent time) is **persisted on
the `email_activity_ledger`**, with the PII fields encrypted at rest (§7.4). Because those
fields are encrypted, they are not SQL-searchable in plaintext (add a search index if
full-text search on bodies is needed). `MAIL_TRACKER_STORE_BODY` separately controls
whether the body is also **forwarded to SMOH**.

**Retention / GDPR erasure** — `php artisan mail-tracker:purge`:

| Invocation | Effect |
| :--- | :--- |
| `--tenant=<id>` | scrub a tenant's content (right-to-erasure) |
| `--before="-90 days"` | scrub content older than a cutoff |
| `--retention` | apply each tenant's `content_retention_days` (or global `MAIL_TRACKER_RETENTION_DAYS`) |
| `--mode=delete` | remove rows entirely instead of scrubbing |
| `--dry-run` / `--force` | preview / skip confirmation |

Default **scrub** nulls the PII columns and stamps `content_purged_at`, but keeps the
dedup keys + audit linkage so erased mail is never re-logged. The scheduler runs
`--retention --force` daily (see `routes/console.php`); it's a no-op until a retention
window is configured.
