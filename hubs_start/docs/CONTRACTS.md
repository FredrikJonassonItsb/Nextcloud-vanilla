# Hubs Start — Implementation Contracts

This is the single source of truth every component and backend file is built
against. Do **not** invent endpoint paths, prop names, event names, or data
shapes that contradict this document. If something is missing, prefer the
shapes already declared in:

- `src/services/api.js` — every network call + JSDoc typedefs (Summary, QueueItem, Recipient, ChannelInfo…)
- `src/services/deepLinks.js` — every outbound link
- `src/services/channels.js` — channel id → label/icon/colour (presentation only)
- `src/services/sections.js` — triage sections + GOV.UK statuses
- `src/store/index.js` — reactive state shape + actions
- `src/views/Start.vue` — how every child component is wired (props + events)

## Hard rules

1. **Stack:** Vue 2.7 + `@nextcloud/vue` v8 (NcButton, NcDialog, NcModal,
   NcListItem, NcCounterBubble, NcLoadingIcon, NcEmptyContent, NcActions,
   NcActionButton, NcTextField, NcSelect…). Import the per-component build, e.g.
   `import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'`.
2. **Brand rule (enforced):** never render the words "Nextcloud" or "Talk" in
   any user-visible string. Use Hubs terminology: *Säkert möte*, *Video & Chatt*,
   *SDK-Meddelande*, *Internpost*, *Säker E-post*, *funktionsbrevlåda*, *kvittens*,
   *Ej tilldelad*, *tillitsnivå/LOA*, *grön/lila bock*.
3. **i18n:** every visible string wrapped in `t('hubs_start', '…')`
   (`translate` from `@nextcloud/l10n`; in templates `this.t`). Swedish is the
   source language.
4. **WCAG 2.2 AA:** interactive targets ≥24×24px (use `.hs-target` or Nc*
   components which comply); focus never hidden by sticky panels; full keyboard
   path for every action; `aria-live="polite"` on the queue for incoming items;
   no drag-only interactions.
5. **Channels are classified server-side.** Components receive an already-resolved
   `channel` and must render it via `channelMeta()` — never re-derive a channel
   from an address suffix on the client.
6. **No client-side fan-out.** Components read from the store (fed by the single
   `fetchSummary` aggregation). Only modals (Smart mottagare, wizard) call their
   own dedicated endpoints.
7. Scoped styles only; use the design tokens in `css/variables.scss` and the
   `.hs-card` shell for every widget.

## Component contracts (Vue, under `src/components/`)

Every component is `export default { name, components, props, data, computed, methods }`.
Props are listed as `name: Type (notes)`. Events are `@event(payload)`.

### HeaderBar.vue
- Props: `loa: String` ('LOA1'|'LOA2'|'LOA3'), `profile: String`.
- Renders: greeting + today's date (localised), a `LoaChip` (see below), and a
  static data-residency badge: `t('hubs_start', 'All data lagras i er driftmiljö')`.
- Emits: `@upgrade-loa()` (bubbled from LoaChip).
- Contains child `LoaChip`.

### LoaChip.vue
- Props: `loa: String`.
- LOA3 → green chip `t('hubs_start','Inloggad med BankID — Tillitsnivå 3')`.
- LOA2/LOA1 → amber chip + NcButton `t('hubs_start','Legitimera med BankID')`.
- Emits: `@upgrade()` when the button is pressed. (Start.vue navigates via
  `deepLinks.loa3UpgradeLink()`.)

### ActionBar.vue
- Props: `apps: Object` (the boot.apps map).
- Four primary NcButtons (type="primary"/"secondary"), each ≥44px tall:
  *Nytt säkert meddelande*, *Boka säkert möte*, *Starta möte nu*, *Sök motpart*.
  Disable/hide *Boka/Starta möte* if `!apps.spreed`; hide *Nytt säkert meddelande*
  if `!apps.mail`.
- Emits: `@new-message()`, `@book-meeting()`, `@start-meeting()`, `@search-recipient()`.

### AttHanteraQueue.vue  (the main widget)
- Props: `items: Array<QueueItem>`, `counts: Object`, `activeChannel: String`,
  `channelCoverage: Array<String>`, `keyboardMode: Boolean`.
- Renders channel filter tabs (Alla + each of CHANNEL_ORDER that is in
  `channelCoverage`) with `NcCounterBubble` per tab; then the five fixed
  `SECTIONS` in order, each a `QueueSection`. Filter `items` by `activeChannel`
  and group by `item.section`.
- Honest coverage line at top: `t('hubs_start','Bevakade kanaler:')` + the
  localised channel labels in `channelCoverage`.
- Empty whole-queue state (NcEmptyContent):
  `t('hubs_start','Allt hanterat — inga ägarlösa ärenden')`.
- `aria-live="polite"` wrapper around the sections.
- If `keyboardMode`, enable j/k navigation + e (done) + a (take) + o (open) on a
  focused item; otherwise no keyboard traps. Provide a small toggle
  (`NcCheckboxRadioSwitch`) `t('hubs_start','Tangentbordsläge')` →
  `@toggle-keyboard(Boolean)`.
- Emits (re-bubbled from sections/items): `@change-channel(String)`,
  `@take(item)`, `@open(item)`, `@done(item)`, `@toggle-keyboard(Boolean)`.
- Contains child `QueueSection`.

### QueueSection.vue
- Props: `section: Object` (one of SECTIONS), `items: Array<QueueItem>`.
- Header = `sectionLabel(section.id)` + count. If empty, render a one-line muted
  empty state appropriate to the section (e.g. otilldelat →
  `t('hubs_start','Inga ägarlösa ärenden')`).
- Each item is a `QueueItem` row.
- Emits: `@take(item)`, `@open(item)`, `@done(item)`.
- Contains child `QueueItem`.

### QueueItem.vue
- Props: `item: QueueItem`.
- Layout (use `NcListItem` where practical): channel icon (`channelMeta(item.channel.channel).icon`),
  verb-first `item.title`, mailbox + dnr line, a status tag via `statusLabel`/`statusTone`,
  optional LOA badge. Primary action **Öppna** (`@open`), secondary **Ta ärendet**
  (`@take`, only when `item.assignment` present and section is `otilldelat`),
  and **Klart** (`@done`, when `item.doneTag` present). Targets ≥24px.
- Never render sensitive personal data; titles come pre-anonymised from the server.
- Emits: `@take(item)`, `@open(item)`, `@done(item)`.

### DagensMoten.vue
- Props: `meetings: Array` (shape: `{ token, title, start, end, participants,
  bankIdRequired, verificationBadge ('green'|'purple'|null), lobbyState, hasCall }`).
- `.hs-card`, title `t('hubs_start','Dagens säkra möten')`. Per meeting: time +
  countdown, BankID badge (green = BankID+personnummer, purple = enbart BankID),
  lobby line (`t('hubs_start','{n} verifierad deltagare väntar i lobbyn',{n})` via
  `n()` plural), a **Kontrollera kamera & mikrofon** link and **Gå till mötet**
  button. Empty → NcEmptyContent `t('hubs_start','Inga möten idag')`.
- Polls lobby via `api.fetchLobbyStatus(token)` for meetings starting soon (optional).
- Emits: `@join(meeting)`.

### KvittensWidget.vue
- Props: `receipts: Array` (shape: `{ messageId, recipient, channel, state
  ('skickat'|'levererat'|'last'|'besvarat'|'problem'), updatedAt, deepLink }`).
- `.hs-card`, title `t('hubs_start','Skickat — kvittenser')`. Show a 4-step status
  pill (Skickat→Levererat→Läst→Besvarat); items with state `problem` sorted first
  with an error tone. Row click → `@open({ deepLink })`. Empty state friendly.
- Emits: `@open(item)`.

### FunktionsbrevladorWidget.vue
- Props: `mailboxes: Array` (shape `{ id, name, unread, unassigned }`).
- `.hs-card`, title `t('hubs_start','Funktionsbrevlådor')`. Per mailbox: name,
  unread count, "Ej tilldelad" count with a deep link to
  `deepLinks.mailboxLink('unassigned')`. No emits (navigates directly).

### BevakningarWidget.vue
- Props: `watching: Array` (shape `{ mailbox, owner, untilDate, direction
  ('incoming'|'outgoing') }`).
- `.hs-card`, title `t('hubs_start','Bevakningar')`. Lines like
  `t('hubs_start','Du bevakar {owner}s brevlåda t.o.m. {date}')` and the reverse.

### BokningsbaraTider.vue
- Props: `configs: Array` (shape `{ id, name, token, bookingUrl, totalBookings }`).
- `.hs-card`, title `t('hubs_start','Bokningsbara tider')`. Per config: name,
  total bookings, **Kopiera bokningslänk** (copies `bookingUrl` via
  `navigator.clipboard`, show `showSuccess`). Empty state friendly.

### NyttaWidget.vue  (förvaltare/chef)
- Props: none (fetches its own stats from a future endpoint; for now read
  optional `store.state` if present, else render the documented placeholder with
  the Digg 30-min schablon explained). `.hs-card`, title
  `t('hubs_start','Nytta hittills')`. Show replaced fax/letters, volume per
  channel, estimated saved time. Clearly mark as indicative until the stats
  endpoint lands (see backend-additions TODO).

### SystemHalsa.vue  (förvaltare only)
- Props: none. `.hs-card`, title `t('hubs_start','Systemhälsa')`. Cards: SDK-log
  status, address-book sync age, background-job health, gallring with a
  **type-to-confirm** guarded "Kör nu", component versions. Read from sdkmc admin
  endpoints already in routes.php (`/api/v2/admin/activityNotificationStatus`,
  `/api/v2/iipax/sdkLog`) and `/api/v2/admin/runExpungeNow` (guarded). This is a
  förvaltare-only safety surface — the gallring action MUST require typing a
  confirmation word before enabling the destructive button.

### SmartMottagare.vue  (modal)
- Uses NcModal/NcDialog. A search field bound to `api.searchRecipients(query)`
  (debounced). Render candidates grouped, each showing the server-resolved
  channel chip via `channelMeta`. Selecting a candidate emits `@chosen(recipient)`
  (Start.vue opens the composer with the resolved channel). Also allow a free
  value classified via `api.classifyRecipient(value)`. The channel choice is the
  server's; the user may override (override is logged server-side, not here).
- Emits: `@chosen(recipient)`, `@close()`.

### MeetingWizard.vue  (modal, 3 steps)
- Step A "Vem": name, personnummer (for BankID binding), mobile (SMS), optional
  secure-email; optional internal colleague. Step B "När": time selection (free
  text + nearest-slot suggestion; CalDAV free-busy is a phase-2 nicety). Step C
  "Skydd": BankID required toggle (default ON for external), SMS toggle, secure
  e-mail invite toggle, with plain-language explanation of what the citizen sees.
- "Boka" calls `api.createSecureMeeting(payload)` (one server-side op). Show a
  confirmation with time, participants, protection level, SMS status.
- Emits: `@booked(result)`, `@close()`.
- `SecureMeetingRequest` payload shape:
  `{ citizen: { name, ssn?, mobile?, secureEmail? }, colleagueUserId?, start, end,
     title, dnr?, requireBankId: bool, sendSms: bool, sendSecureEmailInvite: bool,
     fromMailboxId? }`.

### CommandPalette.vue  (Ctrl+K)
- NcModal with an NcTextField + a filtered list (combobox/fuzzy). Static actions:
  *Nytt säkert meddelande*, *Boka säkert möte*, *Starta möte nu*; plus live
  recipient results via `api.searchRecipients`. Full keyboard nav. Choosing an
  action emits the matching event; choosing a recipient emits `@new-message()`
  (Start.vue opens Smart mottagare) — keep it simple.
- Emits: `@new-message()`, `@book-meeting()`, `@start-meeting()`, `@close()`.

### Onboarding.vue  (replaces firstrunwizard)
- Props: `loa: String`, `apps: Object`, `mailboxes: Array`.
- A 5-step NcModal: (1) welcome + what Hubs Start is, (2) LOA explained
  (tillitsnivåer, why BankID), (3) your function mailboxes, (4) the four primary
  actions tour, (5) "Kom igång"-checklist. Final step emits `@finish()`.
- Emits: `@finish()`.

## Backend contracts (sdkmc additions — `backend-additions/sdkmc/`)

These are **new** files dropped into the sdkmc app (see MANIFEST.md). They are
additive; no existing sdkmc file is edited except an additive block appended to
`appinfo/routes.php` and a few registration lines in `lib/AppInfo/Application.php`
(provided as a patch snippet, not an in-place edit here).

### OCS route table (append to sdkmc `appinfo/routes.php` under `'ocs' => [...]`)
```
['name' => 'OCS\\Summary#summary',            'url' => '/api/v1/summary',                       'verb' => 'GET'],
['name' => 'OCS\\Summary#receipts',           'url' => '/api/v1/receipts',                      'verb' => 'GET'],
['name' => 'OCS\\Recipient#search',           'url' => '/api/v1/recipients/search',             'verb' => 'GET'],
['name' => 'OCS\\Recipient#classify',         'url' => '/api/v1/recipients/classify',           'verb' => 'GET'],
['name' => 'OCS\\SecureMeeting#create',       'url' => '/api/v1/secure-meeting',                'verb' => 'POST'],
['name' => 'OCS\\Meeting#today',              'url' => '/api/v1/meetings/today',                'verb' => 'GET'],
['name' => 'OCS\\Meeting#lobby',              'url' => '/api/v1/meetings/{token}/lobby',        'verb' => 'GET'],
```
All OCS controllers extend `OCP\AppFramework\OCSController`, namespace
`OCA\SdkMc\Controller\OCS`, return `OCP\AppFramework\Http\DataResponse` with the
shapes below, and are `#[NoAdminRequired]`.

### ChannelClassificationService  (`OCA\SdkMc\Service\ChannelClassificationService`)
The ONE place the suffix logic lives (mirrors mail's `getIconTypeForEmail` +
`messageTypeUtils`). Methods:
- `classifyAddress(string $address): array` → `{ channel, channelLabel, messageType }`
  using suffix rules: `@sdk`→sdk/sdk_message; `@personlig`|`@gruppbox`→internal/internal_message;
  `@fax`→fax/fax_message; `@sms`→sms/sms_message; `*.securemail`→secure/secure_email;
  else unknown.
- `classifyRecipientValue(string $value): array` → same shape, with citizen
  heuristics (ssn / email → secure; digits-only → sms or fax per policy).

### SummaryService  (`OCA\SdkMc\Service\SummaryService`)
`getSummary(string $userId, ?string $sinceIds): array` returns the **Summary**
shape from api.js: `{ loa, counts{kravAtgard,otilldelat,nytt,bevakas,klartIdag,problem},
items: QueueItem[], mailboxes:[{id,name,unread,unassigned}], watching:[…],
channelCoverage:[…], maxSinceId }`. It composes existing mappers
(ItslMailboxMapper, AccountItslMailboxMapper, ItslTagMapper, MessageReceiptMapper,
MessageThreadMapper, OutOfOffice data) and Mail's account/mailbox unread —
**server-side**, cached per user with a short TTL, incremental via sinceIds.
Provides REAL server-side counters for the virtual mailboxes (Mina meddelanden /
Otilldelade), which the mail frontend currently hardcodes to 0.

QueueItem shape (must match api.js typedef): `{ id, title (verb-first,
anonymised), channel:{channel,channelLabel,messageType}, status, section,
mailbox, dnr, loa, since (ISO), deepLink:{app:'thread'|'composer'|'mailbox'|'call',
params:{…}}, assignment?:{imapLabel,accountId,threadRootId}, messageId?, doneTag? }`.

### Receipts (Summary controller `receipts` action)
Returns `[{ messageId, recipient, channel, state
('skickat'|'levererat'|'last'|'besvarat'|'problem'), updatedAt, deepLink }]` from
`MessageReceiptMapper` + `MessageThreadMapper` — replaces the 10-minute
PENDING→REJECTED heuristic with the real MW state. Clarify PENDING semantics
before exposing (see TODO in handover).

### SecureMeetingService  (`OCA\SdkMc\Service\SecureMeetingService`)
`create(string $userId, array $request): array` orchestrates: create Talk room
(spreed OCS v4), PUT CalDAV event with the call URL in **LOCATION**, register
BankID/SMS/securemail intents bound to the **event UID** (not PHP session — fixes
the parallel-edit bug), add the e-mail participant with `?resend-invitations`.
Returns `{ token, eventUid, start, end, smsStatus, protection }`.

### Mirror dashboard widgets  (`OCA\SdkMc\Dashboard\*`)
`AttHanteraWidget`, `KvittenserWidget`, `DagensMotenWidget` implement
`IAPIWidgetV2, IButtonWidget, IConditionalWidget, IReloadableWidget` (see
spreed `TalkWidget.php` for the exact pattern) and reuse `SummaryService`. They
make the data visible in the standard dashboard and mobile clients and solve the
double-start-view concern. Registered in sdkmc's Application via
`$context->registerDashboardWidget(...)` (patch snippet in MANIFEST).

### Mail routerhook  (`backend-additions/mail/initITSL-additions.js`)
A small function added to mail's `initITSL.js` that registers a router route /
beforeEach for `/apps/mail/new?type={messageType}&to={value}` which opens the
composer (`ComposerItsl`) with the channel preselected. Documented as a snippet
with the exact integration point.
