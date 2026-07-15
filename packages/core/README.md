# @smoh/mail-tracker-core

Shared contract for SMOH mail tracking, consumed by the thin clients and **mirrored**
(not imported) by the Laravel backend.

- `types.ts` — provider-agnostic domain types (`EmailMessage`, `MailAddress`, …).
- `dedup.ts` — the **canonical** dedup keys: `normalizeMessageId` (RFC 5322 Message-ID)
  and `syntheticKey` (send-time fallback). `App\Support\DedupKey` in the backend is a
  byte-for-byte mirror; a golden SHA-256 vector is asserted in both test suites.
- `smoh/odata.ts` — OData v4 query builders (mirrored by `App\Support\ODataQuery`).
- `smoh/contract.ts` — the `SmohClientContract` the backend's `SmohClient` fulfills.

```bash
npm run build     # tsc → dist/
npm test          # builds, then runs the node:test suite
```

SHA-256 uses the Web Crypto API (`crypto.subtle`) — available in browsers, the Office.js
webview, and Node 20+ — so the package bundles cleanly with no Node-only dependency.

**Parity rule:** if you change canonicalization in `dedup.ts` or a builder in
`smoh/odata.ts`, change the PHP mirror in the same commit, or dedup silently drifts.
