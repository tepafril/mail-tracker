/**
 * Build-time configuration, injected by webpack DefinePlugin from the environment.
 * See .env.example for how to set these when building/serving the add-in.
 */

/** Base URL of the Laravel backend (the brain). All API calls target `${BACKEND}/api/v1`. */
export const BACKEND_BASE_URL: string = process.env.BACKEND_BASE_URL ?? 'https://localhost:8000';

/** Multi-tenant Entra app (client) id registered for this add-in (MASTER-PLAN §5.2). */
export const ENTRA_CLIENT_ID: string = process.env.ENTRA_CLIENT_ID ?? '';

/**
 * The custom API scope to request — NEVER Graph, NEVER the id token (§5.1). Defaults to
 * `api://<client-id>/access_as_user` when not explicitly provided.
 */
export const API_SCOPE: string =
  process.env.API_SCOPE && process.env.API_SCOPE !== ''
    ? process.env.API_SCOPE
    : `api://${ENTRA_CLIENT_ID}/access_as_user`;

export const API_BASE = `${BACKEND_BASE_URL.replace(/\/$/, '')}/api/v1`;

/**
 * DEV/DEMO ONLY. When true, the add-in skips MSAL/Entra and authenticates via the
 * backend's /auth/dev-login endpoint — so you can exercise the flow with no Azure app.
 * Requires the backend to have MAIL_TRACKER_DEV_AUTH=true. Never ship this enabled.
 */
export const DEV_FAKE_AUTH: boolean = process.env.DEV_FAKE_AUTH === 'true';
