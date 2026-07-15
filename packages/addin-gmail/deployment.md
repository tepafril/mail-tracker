# Gmail Add-on — deployment descriptor & gating

## HTTP deployment (alternate runtime)

Register this deployment in the Google Workspace Marketplace SDK. Triggers point at the
Laravel backend; replace `https://backend.example` with your hosting origin.

```json
{
  "addOns": {
    "common": {
      "name": "SMOH Mail Tracker",
      "logoUrl": "https://backend.example/assets/gmail-logo.png",
      "useLocaleFromApp": true
    },
    "gmail": {
      "homepageTrigger": {
        "runFunction": "https://backend.example/api/gmail/addon/contextual"
      },
      "contextualTriggers": [
        {
          "unconditional": {},
          "onTriggerFunction": "https://backend.example/api/gmail/addon/contextual"
        }
      ],
      "universalActions": [
        {
          "text": "Log to SMOH CRM",
          "runFunction": "https://backend.example/api/gmail/actions/log"
        }
      ]
    }
  }
}
```

## OAuth scopes

- **Add-on runtime** (contextual card / per-message token): `gmail.addons.current.message.readonly`
  (headers/metadata) — escalate to body scopes only if strictly needed.
- **Zero-touch sync** (Phase 2) needs a **full offline** grant (`access_type=offline`)
  with `gmail.metadata` (headers only) or `gmail.readonly` (body). The add-on's own
  scopes cannot drive `users.watch`. Both are **RESTRICTED** scopes.

## Verification token contract

The backend verifies, per request:

- `authorizationEventObject.userIdToken` — Google-signed JWT identifying the user
  (`sub`); `aud` = `GOOGLE_CLIENT_ID`, `iss` ∈ `GOOGLE_ISSUERS`. This also proves the
  request came from Google.
- Pub/Sub push (Phase 2): the `Authorization: Bearer` OIDC token — `aud` = the push URL,
  `email` = `GOOGLE_PUBSUB_SERVICE_ACCOUNT`, `email_verified = true`.

## Gating checklist (MASTER-PLAN §6.5 — biggest timeline risk)

1. OAuth **brand verification**.
2. **Restricted-scope verification** (the Gmail scope requires it).
3. Annual third-party **CASA Tier 2 / AL2** security assessment (App Defense Alliance).

Start these in parallel with development; a testing build is capped at ~100 users until
cleared.
