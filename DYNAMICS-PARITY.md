# SMOH Mail Tracker → Dynamics 365 parity roadmap

_Last updated: 2026-07-16_

## Goal
Make SMOH Mail Tracker behave like the **Dynamics 365 App for Outlook + server-side
synchronization**: turn Outlook email (and calendar) into CRM activity records, both
**manually** (a pane button) and **automatically** (server-side, no user action), with the
same matching, "Set Regarding", filtering, and correlation behaviors Dynamics users expect.

## How Dynamics 365 works (reference)
Two tracking modes, plus a set of behaviors:
- **App for Outlook** (client) — a pane with **Track** and **Set Regarding**; the user links
  an email to a CRM record.
- **Server-side synchronization** (server) — Dynamics reads the mailbox via Exchange/Graph
  and **auto-tracks** based on the user's rule.
- **Automatic tracking rules** (per user): *All* · *Responses to CRM email* · *From CRM
  leads/contacts/accounts* · *None*.
- **Set Regarding** — link the activity to any parent record (Account, Opportunity, Case,
  Lead, Contact).
- **Matching** — resolve senders/recipients to **contacts, leads, and accounts**.
- **Correlation** — thread replies and attach the whole conversation to the same record
  (via a tracking token or smart matching).
- Also: **attachments**, **appointments/meetings**, **folder-level tracking**, and a pane
  that shows **Tracked / Set Regarding / Untrack** state.

---

## Parity scorecard (current state)

| Dynamics capability | SMOH today | Status |
|---|---|---|
| Manual track from a pane | Add-in **Log to CRM** button | ✅ Have |
| Server-side auto-track (no user) | Graph subscription → `ParseGraphNotificationJob` | ✅ Have |
| Email → CRM activity record | `EmailActivityLedger` → SMOH `CRM.Email` | ✅ Have |
| Match sender/recipient to CRM | `findContactByEmail` (**contacts only**) | ⚠️ Partial |
| Auto-track only known contacts | `known_contacts` rule (default) | ✅ Have |
| Configurable tracking rules | `TrackRule`: all / known_contacts / none (per mailbox) | ✅ **Done (Phase A)** |
| Set Regarding to a parent record | `regarding` is always the **contact** | ❌ Gap |
| Dedup by Message-ID | Message-ID + synthetic key | ✅ Have |
| Conversation correlation / "responses to CRM email" | partial subject/synthetic keying | ⚠️ Partial |
| Attachments on the activity | not captured | ❌ Gap |
| Appointments / meetings | email only (no calendar) | ❌ Gap |
| Folder-level tracking | none | ❌ Gap |
| Pane shows Tracked/Untrack/Set Regarding | pane shows match + one-click log only | ❌ Gap |

**Bottom line:** the *foundation* (both tracking modes, activity creation, contact match,
dedup, retention) is done. Parity work is the CRM-linkage depth: rules, regarding, broader
matching, correlation, attachments, calendar, and pane UX.

---

## Roadmap (priority order)

### Phase A — Configurable auto-track rules ✅ DONE (2026-07-16)
Match Dynamics' per-mailbox tracking options instead of the fixed "known contacts only".
Shipped: `App\Enums\TrackRule` (all / known_contacts / none), `users.track_rule`,
`--rule=` on the enrollment command, enforced in `LogEmailActivityJob`. `responses_only`
deferred to Phase D. (Below: original notes.)
- Add a `track_rule` enum on the enrolled `User` (or `Tenant` default): `all` ·
  `known_contacts` (current behavior) · `responses_only` · `none`.
- Enforce it in [LogEmailActivityJob.php](packages/backend/app/Jobs/LogEmailActivityJob.php)
  around the contact-match gate (L87–103): `none` → skip; `all` → log even without a match
  (regarding left empty / to a catch-all); `responses_only` → only if correlated to a prior
  CRM email (see Phase D).
- Set the rule via `mail-tracker:track-mailbox … --rule=known_contacts`.
- Files: new `App\Enums\TrackRule`, migration on `users`, `TrackMailboxCommand`, `LogEmailActivityJob`.

### Phase B — Set Regarding (link to parent records)
The biggest functional gap. Let an email link to an **Account / Opportunity / Case / Lead**,
not just the contact.
- Extend the SMOH contract to fetch those record types + accept an arbitrary regarding
  target: [packages/core/src/smoh/contract.ts](packages/core/src/smoh/contract.ts),
  [odata.ts](packages/core/src/smoh/odata.ts).
- Generalize the activity payload: [EmailMessageData::toSmohActivity](packages/backend/app/DataObjects/EmailMessageData.php#L97)
  to take a `{regardingId, regardingType}` instead of assuming contact.
- Store it: add `regarding_type` to `EmailActivityLedger` (already has `contact_id`).
- Backend endpoint + `SmohClient` methods to **search records** and **set/change regarding**.
- Add-in **Set Regarding** picker in the task pane
  ([packages/addin-outlook/src/taskpane](packages/addin-outlook/src/taskpane)).

### Phase C — Lead + account matching
Dynamics matches leads/contacts/accounts, not just contacts.
- Extend `SmohClient::findContactByEmail` → `resolveRecipient()` that tries **contact →
  lead → account** and returns `{id, type}`.
- Feed the resolved type into the regarding from Phase B.
- Files: [SmohClient.php](packages/backend/app/Services/Smoh/SmohClient.php), core contract,
  `LogEmailActivityJob`.

### Phase D — Conversation correlation
Thread replies and attach them to the same regarding record ("responses to CRM email").
- Use `conversationId` / `internetMessageHeaders` (References/In-Reply-To) from Graph
  ([GraphClient::getMessage](packages/backend/app/Services/Graph/GraphClient.php) `$select`).
- On ingest, if a message correlates to an already-tracked one, inherit its regarding.
- Files: [dedup.ts](packages/core/src/smoh/../dedup.ts), `DedupKey`, `EmailIngestionService`,
  `GraphMessageMapper`.

### Phase E — Attachments
Capture attachments onto the CRM activity (Dynamics tracks them).
- Fetch `/messages/{id}/attachments` via Graph; upload to SMOH as activity attachments.
- Files: `GraphClient` (new method), `SmohClient` (attachment write), `ParseGraphNotificationJob`.

### Phase F — Appointments / meetings
Dynamics tracks calendar items as appointment activities.
- New Graph subscription on `users/{id}/events`; map to a SMOH `CRM.Appointment` activity.
- Files: `GraphSubscriptionManager` (events resource), a new mapper + ingestion path, contract.

### Phase G — Folder-level tracking (optional)
Dynamics can auto-track anything dropped into a designated Outlook folder.
- Subscribe to a specific `mailFolders('<id>')/messages` resource per user; track all in it.
- Files: `GraphSubscriptionManager`, enrollment command option.

### Phase H — Pane parity (add-in UX)
Bring the task pane to Dynamics' interaction level.
- Show **Tracked / Not tracked** state for the open message, an **Untrack** action, and the
  **Set Regarding** picker (Phase B).
- Files: [packages/addin-outlook/src/taskpane](packages/addin-outlook/src/taskpane),
  new backend endpoints (get tracking state, untrack).

---

## Suggested sequencing
1. **A + C** first (configurable rules + broader matching) — small, high-value, server-only.
2. **B** (Set Regarding) — the headline feature; needs core + backend + add-in changes.
3. **D** (correlation) — enables the "responses to CRM email" rule from A.
4. **E, F, G, H** — depth features, do as demand dictates.

## Verification (per phase)
- Unit/feature tests in `packages/backend/tests/Feature` mirroring `GraphSyncTest` (fakes).
- Live: enroll a mailbox, exercise via `graph:simulate` (fake) then a real send, confirm the
  resulting SMOH activity has the expected regarding/type/attachments at `/dev/emails`.
- Add-in changes: sideload `manifest.connector.xml`, verify the pane state/actions.

## Dev infrastructure — mock SMOH (built 2026-07-16)
Since real SMOH doesn't exist yet, an **in-backend mock SMOH CRM** stands in for it and
doubles as the API spec: dummy tables (`mock_accounts/contacts/leads/emails`) behind an
OData v4 + `/auth/login` service at `/api/mock-smoh` (`App\Http\Controllers\Dev\MockSmohController`,
dev-gated). Tenant `tepafril` is pointed at it with `SMOH_FAKE=false`, and the **real**
`SmohClient` path is validated end-to-end (login → `$metadata` → contact match → activity
create). B/C/E are now built against this. Real SMOH later = swap the URL/creds and confirm
the field mapping — the mock defines exactly what it must implement.

## Out of scope / notes
- The mock's field names (`regarding_id`/`regarding_type`, contact `email`/`email_business`,
  etc.) are the working contract; a real SMOH must match them or the mapping in
  `EmailMessageData`/`ODataQuery` gets adjusted — see [AUTO-TRACKING.md](AUTO-TRACKING.md).
- Dynamics injects a **tracking token** into subjects; we deliberately prefer Message-ID
  correlation (no subject pollution) — Phase D keeps that approach.
- Gmail parity (the Gmail add-on / Pub/Sub sync) is a separate track; this doc is Outlook.
