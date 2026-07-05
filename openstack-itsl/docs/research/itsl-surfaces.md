# ITSL human↔agent interaction surfaces — recon

Date: 2026-07-04. Grounded in: `natebjones/digests/deck-capabilities.md` (verified Deck/Talk facts), `hubs_arende/lib/Integration/Client/DeckClient.php`, `hubs_arende/lib/Service/ArendeService.php` (DeckClient call sites), and `hubs_start/src/components/socialsekreterare/{MinDagHeader,ArendeKort,NastaAtgardKnapp}.vue` (house UX patterns). ITSL's ACTUAL internal boards live on itsl.hubs.se, which was NOT inspected — structure questions are parked in the M0 section, not assumed.

---

## 0. What the codebase proves the team already has

- **Server-side Deck driving, production-hardened** (`hubs_arende/lib/Integration/Client/DeckClient.php`): resolve-or-create by exact title (board/stack/label), two-step create (POST card → PUT `assignLabel`), compensation delete, `(boardId, cardId)` persisted as a `pekare` row (`objekt_typ='deck_card'`, boardId stashed in `riktning`), service-account Basic auth, graceful NO-OP degradation, PII-safe `safeRef()` logging. Call sites: `ArendeService.php` lines ~582–617 (create+label+pointer), ~1408 (re-label), ~1616/2341 (read/teardown).
- **Known API friction already encoded**: card URLs need `stackId` in the path, so `findStackIdForCard()` scans `GET /boards/{b}/stacks` — the "store the triple, refresh stackId after moves" lesson is already learned in-house.
- **Vue/dashboard competence** (hubs_start, Vue 2.7, `@nextcloud/vue` components, dashboard-category NC app):
  - `MinDagHeader.vue`: greeting header + **Dagspulsen** — four clickable counters that double as list filters ("fristerBrinner, motenIdag, attSignera, nyaInflode"). This is exactly the "vad väntar på mig" pattern.
  - `ArendeKort.vue`: one card per work item with process stepper, deadline chip (`FristChip`), provenance chip, assignment band, tab counters visible without expanding, and a self-service "Ta ärendet" claim button on unassigned items.
  - `NastaAtgardKnapp.vue`: ONE dominant primary action derived from a state machine (`nastaFor(arende)`) + an overflow `NcActions` menu of "other lawful actions in this phase". Low-cognitive-load, action-first.
- Net: if a custom human-facing agent surface is wanted, the team's established idiom is *counters → cards → one glowing next action*, built on `NcButton`/`NcActions`/`hs-card`. Reuse it; don't invent a new idiom.

---

## 1. Which Deck events can trigger "assign-to-agent"?

Webhook substrate: `webhook_listeners` (bundled since NC 30) + Deck's `ACardEvent implements IWebhookCompatibleEvent` (verified on Deck `main`; **shipped-version unverified** — M0 item). Payload = full serialized card (incl. `labels[]`, `assignedUsers[]`), server-side dot-notation filters at registration (e.g. pin to one boardId). Delivery is via background jobs: **up to ~5 min lag by default**; dedicated `occ background-job:worker` processes needed for near-real-time. No HMAC on outgoing posts — secret URL/IP-allowlist the receiver.

Ranked trigger options (reliability × latency × human ergonomics):

1. **Card moved to a dedicated stack** (e.g. drag to "Till agent"). Move = `PUT …/reorder {stackId,order}` fired from the Deck UI by drag-and-drop — the most natural human gesture. Should surface as a card-update event with the new `stackId` in the payload (filterable). Reliability: high *if* reorder fires an `ACardEvent` subclass on the deployed version (M0 verify). Latency: webhook lag (5 min default / seconds with workers). Prereq: provision the trigger stack on every enrolled board.
2. **Label applied** (e.g. `agent-ready`). Two clicks in the card UI; label list is embedded in the card serialization so the receiver can check it. Caveats: labels are **board-scoped** — the label must be provisioned per board (DeckClient's resolve-or-create ports 1:1); server-side filter over a label *array* may not be expressible in dot-notation filters → filter on boardId server-side, on label receiver-side. Reliability: high if label-assign fires a webhook-compatible event (digest marks label/assignment event compatibility **uncertain** — M0 verify).
3. **Assignment to the bot user** (`assignUser` on the agent's NC account). Semantically the cleanest ("give it to the agent") and gives native attribution. Two prereqs: (a) the agent account must be a **board member via ACL** (`POST /boards/{id}/acl`) on every enrolled board or it cannot be assigned at all; (b) whether assignment fires a webhook-compatible event is **explicitly uncertain** in the digest. Fallback if the event doesn't fire: the agent polls its own cards (see below). Provisioning implication: board enrollment script = add ACL + create trigger stack + create labels, idempotently.
4. **Comment @mention of the agent** (`@agent-name` in a card comment). Worst webhook coverage: comments are NC-comments, no documented webhook-compatible Deck comment event. The mention *does* generate a native NC notification to the mentioned account, so the agent can poll the Notifications OCS API as itself — latency = poll interval, extra moving part. Keep as a nice-to-have conversational trigger, not the primary path.

**Universal fallback (version-proof, deterministic latency):** ETag polling of `GET /boards/{b}/stacks` (304s are cheap; a card change bumps the board ETag). This also carries the case where the deployed Deck predates `IWebhookCompatibleEvent`. Note **no server-side card filtering exists** — the poller filters client-side, which DeckClient's `findStackIdForCard()` already demonstrates.

**Claim-race caveat** (from digest): no atomic compare-and-swap on reorder — protocol is assignUser-self → re-read → proceed only if sole assignee; assignee is the lock, stack is the display.

---

## 2. What a human sees when an agent acts

Native, zero build (assuming the agent has its own NC account + board ACL):

- **Card face**: assignee avatar appears/changes on the card front; stack moves are visible on the board immediately; labels and duedate changes render natively.
- **Comments tab**: agent comments appear with the agent account's avatar/display name; one-level threading via `parentId`; **1000-char cap** per comment.
- **Activity stream**: Deck feeds the standard NC Activity app — card created/updated/moved events show in the board's activity sidebar and in each member's personal Activity feed, attributed to the agent account. (Activity OCS API shape for the `deck` filter unverified — display in the NC UI is the claim here.)
- **Notifications (the bell, + email/mobile push per user settings)**: the human gets a native notification when the agent (a) **assigns a card to them** (hand-back gesture), (b) **@mentions them in a comment**, (c) when a duedate the agent set comes due. This is the built-in "agent needs you" channel — design the protocol around assign-back + @mention rather than building push.

Needs agent_engine build:

- **Cross-card summaries** ("your agent completed 3, is blocked on 1") — nothing native aggregates per-actor.
- **Receipts longer than 1000 chars** — chunked threaded comments, or full artifact in card description/attachment (v1.1 `file` type = real NC files) with a summary comment linking to it.
- **Proactive push with content** — Talk bot message (up to 32k chars, occ-registered, HMAC-verified) to a person or channel; the capture bot infrastructure on the build list covers this.
- **Attribution policy**: one NC account per agent → clean avatars, per-agent revocation, and author-only comment editing works per agent. A single shared service account works but muddles "who did what" in every native surface.

---

## 3. Human-facing overview: "vad gör min agent / vad väntar på mig"

Options, cheapest first:

| Option | What it gives | Effort | Notes |
|---|---|---|---|
| **A. Deck-native conventions** (board filters, assignee avatars, duedates, "Upcoming cards" dashboard widget) | "Waiting on you" = agent assigns human + sets duedate → card shows in the human's native Deck dashboard widget and board filter (assignee/label/due filtering exists in the web UI, client-side) | ~0 — conventions + provisioning only | Weakest semantics (duedate-oriented); undocumented `GET /overview/upcoming` exists but treat as unstable. Right M0 answer. |
| **B. Talk digest** | Scheduled agent_engine job posts a daily/on-event summary to a Talk conversation via the bot API (32k chars, `replyTo`, `silent`) | Small: 1–2 dev-days *on top of* the capture bot already on the build list | Push-only, not interactive; budget for 429 backoff. Good complement to A. |
| **C. NC Dashboard widget** ("Min agent") | PHP `IWidget` + small JS panel on the NC dashboard: counters + top-N cards needing the human | Small-medium: 2–4 dev-days | Deck itself ships a dashboard widget, so the pattern is proven on-instance. Data via Deck REST (service account) or agent_engine's own tables. hubs_start is literally a dashboard-category app — the competence is in-house. |
| **D. Vue page in agent_engine** | Full page in hubs_start idiom: Dagspulsen-style clickable counters → ArendeKort-style cards → NastaAtgardKnapp-style single primary action ("Godkänn", "Svara agenten") | Largest: 1–2 weeks first version incl. backend endpoints | Only justified when agent volume/interaction depth outgrows A–C. Low technical risk given hubs_start prior art (Vue 2.7, @nextcloud/vue, state-machine-derived next action). |

Sensible sequence: **A at M0 → B once the capture bot exists → C when volume justifies → D only if agents become a core product surface.**

---

## 4. Constraints that shape the design

1. **1000-char comment cap** → receipts chunk (threaded `part n/m` via `parentId`) or live in description/attachments with a summary comment. Any "ledger comment" must stay under 1000 chars.
2. **Author-only comment edit/delete** (actorId match) → an updatable status ledger is only updatable by the account that wrote it. Humans cannot correct agent comments; agents cannot edit human comments. Per-agent accounts make ledgers per-agent-editable.
3. **Board-scoped labels** → `agent-ready`/priority labels have different IDs per board; provision per board with resolve-or-create by exact, case-sensitive title (DeckClient behavior). A rename on one board silently breaks matching there only.
4. **No server-side card filtering/search** (documented API) → every "cards for X" view = fetch `GET /boards/{b}/stacks`, filter client-side. ETags keep polling cheap, but payloads grow with board size → archive done cards aggressively (`PUT …/archive` exists).
5. **~5-min webhook lag by default** → "hand to agent" won't feel instant without `occ background-job:worker` processes on the host. UX must either run workers or set expectations (agent acks via comment when it actually starts).
6. **Bot board membership is a hard prereq** → assignability, commenting, and visibility all require ACL membership; enrollment of a board = ACL + stack + labels, scripted idempotently. Un-enrollment = ACL removal (clean revocation).
7. **stackId in card URL paths** → store `(boardId, stackId, cardId)`, refresh stackId after any move (in-house lesson from `findStackIdForCard`).
8. **No signing on outgoing webhook_listeners POSTs** → receiver auth = secret URL path + IP allowlist (or custom NC app receiving `OCA\Deck\Event\*` in-process as the escape hatch — ITSL already ships NC apps).
9. **No atomic claim** → assignee-as-lock protocol; write the two-agent race test.
10. **House logging discipline**: card titles/labels never logged verbatim (`safeRef()` len+sha256-prefix pattern, OSL 26 kap/GDPR). Internal ITSL boards likely carry no client PII, but keep the pattern — it costs nothing. (Per the PII principle: showing content to authorized humans is fine; leaking across authorization boundaries is not.)

---

## M0: must ask / verify on itsl.hubs.se (do NOT invent)

**About the humans' actual boards (unknown — never inspected):**
1. Board topology: per-team, per-person, or per-project boards? What stacks/columns exist today and what do they mean?
2. Existing label conventions — does `agent-ready` (or any machine label) collide with human labels?
3. Who owns/administers the boards (personal boards vs shared with group ACLs)? Who can grant the agent account ACL membership?
4. Where do ITSL humans actually live day-to-day — Deck UI, NC dashboard, or Talk? (Decides A vs B vs C in §3.)
5. Which team/person is the pilot, and which one board gets enrolled first?
6. Which Talk conversation is the capture/digest channel; is a bot welcome there?
7. Account policy: per-agent NC accounts (clean attribution, per-agent revocation) vs one shared bot account — any IT/security preference?

**Version/behavior checks on the live instance (30–60 min with an admin session):**
8. NC + Deck versions; does registering a webhook for `OCA\Deck\Event\CardCreatedEvent` succeed (i.e. did `IWebhookCompatibleEvent` ship)?
9. Which operations actually fire webhook-compatible events: reorder (stack move)? assignLabel? assignUser? (Digest verified only that ACardEvent subclasses qualify; per-operation firing is unconfirmed.)
10. Is `webhook_listeners` enabled, and can dedicated background-job workers run on that host (sub-5-min latency)?
11. Card `PUT` partial-update behavior on the deployed version (GET-merge-PUT fallback if not).
12. Comment 1000-char enforcement point; Talk bot 429 thresholds; probe undocumented `overview/upcoming` + `search` routes.
