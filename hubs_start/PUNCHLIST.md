# Hubs Start — Punchlist

Status after the build workflow (27 agents) + automated review (3 reviewers) +
my fix pass. **All blocker and major findings are fixed.** What remains below is
minor hardening + genuine integration gaps that need the running Linux dev
environment (real DB schema, spreed/DAV, MW) to finish and verify.

Legend: ✅ fixed in this pass · ⏳ remaining (non-blocking)

## ✅ Fixed (blockers)

- **KvittenserWidget** called `SummaryService->getReceipts()` (didn't exist) →
  now calls `buildReceipts($userId, 'all', $limit)`.
- **DagensMotenWidget** called `SummaryService->getTodaysMeetings()` (didn't
  exist) → extracted a shared **`MeetingService`** (getTodaysMeetings/getLobby);
  both the widget and `MeetingController` now delegate to it. Single source of truth.
- **NyttaWidget** used `this.n` without importing `translatePlural` → explicit
  `import { translatePlural as n }` + added to methods (no longer relies on the
  global Vue prototype).

## ✅ Fixed (majors)

- **CommandPalette** `NcModal :title` → `:name` (v8 prop rename; title was dropped).
- **Start.vue** `startInstantMeeting` now sets `meetingStartNow` and binds
  `:start-now` on `MeetingWizard`, so "Starta möte nu" differs from "Boka säkert möte".
- **SecureMeetingService** `defaultTitle` now uses `IL10N` (`$this->l->t(...)`),
  injected into the constructor.
- **SummaryService.resolveChannelForMessage** was dead code (always returned
  `unknown`) → now traces the message to its function mailbox via the existing
  `lookupMailboxIdForMessage()` join and classifies the real address.
- **SummaryService** unassigned queries (`countUnassigned` + `fetchUnassignedThreads`)
  now restrict to the **INBOX** (`LOWER(mb.name)='inbox'`), matching the docblocks
  and keeping counts consistent with the item list.
- **AttHanteraQueue** WCAG: the queue region is now focusable (`role="group"`,
  `tabindex="0"`, accessible name) and the keyboard help is shown **always**
  (adapts text when keyboard mode is off), not only in keyboard mode.

## ✅ Fixed (minors picked up while in the files)

- **SecureMeetingService** `getUri()` now narrowed via `instanceof ICalendar`
  before the call (psalm/phpstan clean).
- **DagensMotenWidget** `formatTime()` casts `l('time', …)` to `(string)`.

## ⏳ Remaining — verify/finish in the Linux dev environment

### Backend (sdkmc additions)
- **Receipt `updated_at`** — `sdkmc_message_receipt` has no `updated_at` column,
  so the 4-step pill timestamps are `null`. Add a migration/column (or read from
  `receipt_data`) for accurate progression times. `// TODO(hubs-start)` in
  SummaryService.fetchReceiptRows.
- **PENDING semantics** — `mapReceiptState()` treats `pending/sent` as `skickat`
  and only explicit reject/fail/error as `problem`. Confirm the canonical MW
  status vocabulary with the SDK/broker team before trusting "Problem".
- **SummaryController vs SummaryService receipt duplication** — the controller
  re-implements receipt listing/state-mapping. Make `receipts()` delegate to
  `SummaryService::buildReceipts()` and delete the controller copy so the
  state-map lives in one place.
- **Fabricated assignment label** — `buildOtilldeladeItems()` falls back to a
  synthetic `$assignee_{userId}` imapLabel when no real tag exists; "Ta ärendet"
  would target a non-existent label. Resolve/create the real assignment tag
  (`tagMapper`) or omit the `assignment{}` block (UI then hides the action).
- **INBOX predicate column** — verify `mail_mailboxes.name` is the right column /
  value (`INBOX`) against the deployed NC Mail schema; adjust if Mail stores the
  inbox via `special_use` instead.
- **Cross-app route safety** — the three dashboard widgets resolve
  `hubs_start.Page.index`; if hubs_start is disabled, `linkToRouteAbsolute`
  throws. Wrap in try/catch with a dashboard fallback, or gate `isEnabled()` on
  hubs_start being enabled.
- **Secure-meeting loopback credentials** — `createTalkRoom` /
  `addEmailParticipant` POST to spreed OCS over loopback `IClientService` and need
  a service-account / app-password for the organizer; prefer a direct
  `OCA\Talk\Service\RoomService` call when guaranteed loadable. `// TODO(hubs-start)`.
- **Intent eventUid matching** — SMS/securemail intents store the `eventUid` but
  `IntentProcessorService` still pops by email from the session; extend it to
  match on `eventUid` for full session independence (BankID is already durable).
- **`ConversationBankIDAuthMapper::findByConversation()`** — add it so
  MeetingService stops doing an inline read against `sdkmc_conv_bank_auth`.
- **`resolveLoa()`** returns LOA3 until sdkmc's login-security state is exposed as
  an injectable service (the SPA refreshes LOA live from `getSettings` anyway).

### Frontend (minor a11y polish)
- **SystemHalsa** — announce the gallring "armed" (confirm-ready) state via
  aria-live; currently only the label is in the live region.

### Mail hook
- **SDK `?to=` deep-link** — a raw `?to=` can't be resolved to the SDK
  organizationAddress+functionAddress pair, so the SDK composer opens with the
  type preselected but empty address pickers. Other channels prefill fully.
- **No named `/new` route in mail** — the SPA guard matches on path tail + `?type=`;
  switch to a route name if mail later adds one.

## Verification done in this (Windows) environment
- `node --check` passes on **all** plain JS (services, store, tests, l10n).
- `<script>` blocks of **all 18** Vue SFCs pass `node --check` (extracted as ESM).
- Brand-rule grep: **no** "Nextcloud"/"Talk" in any `t()/->t()` user-visible string.
- PHP not runnable here (no php binary) and `.vue` templates + full webpack build
  require the Linux toolchain (`make nc-up` + `make webpack`); run `composer
  test:unit` / phpstan and `npm run test` there.
