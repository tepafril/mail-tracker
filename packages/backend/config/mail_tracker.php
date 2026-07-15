<?php

declare(strict_types=1);

return [
    /*
    | Domains whose mail the zero-touch sync engine drops (internal / colleague mail).
    | Comma-separated in env. Supports exact domains and "*.suffix" wildcards.
    | Not applied to the user-driven one-click path.
    */
    'blacklist_domains' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('MAIL_TRACKER_BLACKLIST_DOMAINS', '')))
    )),

    /*
    | Whether to send the (sanitized) email body to SMOH. When false, only headers /
    | metadata are logged (subject, participants, direction, timestamp) — see the
    | body-logging open decision in MASTER-PLAN §10.
    */
    'store_body' => filter_var(env('MAIL_TRACKER_STORE_BODY', true), FILTER_VALIDATE_BOOL),

    /*
    | Max body length (characters) sent to SMOH; longer bodies are truncated.
    */
    'max_body_length' => (int) env('MAIL_TRACKER_MAX_BODY_LENGTH', 100_000),

    /*
    | DEV/DEMO ONLY: enable the /api/v1/auth/dev-login endpoint that issues a token for a
    | seeded demo user without a real OIDC provider. NEVER enable in production.
    */
    'dev_auth' => filter_var(env('MAIL_TRACKER_DEV_AUTH', false), FILTER_VALIDATE_BOOL),

    /*
    | Global default retention (days) for stored email CONTENT. The daily
    | `mail-tracker:purge --retention` job scrubs the PII of ledger rows older than this
    | (per-tenant `content_retention_days` overrides it). null = retain indefinitely.
    */
    'retention_days' => ($d = env('MAIL_TRACKER_RETENTION_DAYS')) !== null && $d !== ''
        ? (int) $d
        : null,
];
