# IxD Proposal B — "Thin UI where it pays"

**Angle:** Native Deck/Talk/Notifications mechanics as the base; spend UI budget on exactly two things — an NC Dashboard widget ("Min agent") and the invisible takeover/mirror machinery inside `agent_engine`. No new pages, no Deck forks, no custom card sidebar. The team's humans keep working on the boards they already have; the agent protocol stays byte-identical on the Agent Engine board; a thin, deterministic PHP layer translates between the two worlds.

Date: 2026-07-04. Builds on BYGGPLAN.md (must not break §3 claim/receipts/one-task-per-run), KARTLAGGNING.md, `nate-team-model.md`, `itsl-surfaces.md`, `deck-capabilities.md`. Everything here is additive to the approved build plan; deviations from Nate's protocol are declared in §8.

---

## 1. Design stance

Three observations drive every choice below:

1. **The humans already live in Deck.** ITSL's people have their own boards and cards ("människotavlor"). The single worst outcome is forcing them to learn `[agent instructions][reb-claude][task]`, 16 receipt tokens, and an 8-section template before they can hand anything to an agent. Adoption dies there (BYGGPLAN Risk #2, #10).
2. **Nate's protocol must stay pure on ONE board.** The Agent Engine board is the machine surface: title grammar, eligibility, atomic claim, receipts, ledger — verbatim. Fidelity is cheap if we never ask humans to touch it directly.
3. **Therefore the product is a *translator*, not a UI.** The high-leverage build is: (a) a takeover mechanic that converts "assign the agent on MY card" into a protocol-perfect engine card, (b) a two-way mirror that renders protocol state back as plain Swedish on the human's own card, and (c) one dashboard widget that answers "vad väntar på MIG / vad gör min agent" without opening any board. Native NC notifications (bell, mobile push, email per user settings) do all the pushing — we build zero notification infrastructure beyond what BYGGPLAN §1.2 already has.

The team's own UX idiom (itsl-surfaces §0: *counters → cards → one glowing next action*, `MinDagHeader`/`ArendeKort`/`NastaAtgardKnapp`) is reused in the widget. Nothing new is invented visually.

---

## 2. How Nate thinks the team collaborates — detailed analysis, and what we do with it

Condensed from `nate-team-model.md`; each row ends with the ITSL surface that carries it in this design.

### 2.1 People ↔ people

| Nate's mechanism | Substance | Our carrier |
|---|---|---|
| Naming meeting first | Team locks names/labels/statuses/codes before automation; "most failed first runs come from mismatched names" | M0 kickoff (already in BYGGPLAN). This proposal adds two names to lock: bot display names (`Reb (agent)` …) and the takeover label |
| Routing map = org chart | Private standing issue: per human — assignee, agent codes, ownership area | Standing card on Agent Engine board (BYGGPLAN §3.7). Humans *never need to read it* for takeovers: picking a bot avatar IS routing (§3 below); the map governs agents and near-miss checks |
| `requester` field in every task | The human↔human escape hatch travels inside the work item | Takeover fills `## Requester` automatically from the assigning user — humans get this for free |
| Approval among humans | "Human approval is required for publishing and customer-facing changes"; grants are written into the issue, team-visible | Unchanged (BYGGPLAN §7). Takeover-born cards NEVER carry grants — approvals only from explicit human text (§3.4) |
| Stakeholder update | Outbound summaries "only when shipped, only what's true", send needs confirmation | `stakeholder-update` skill (BYGGPLAN §6), unchanged |
| **GAP 12** — no human↔human channel/rituals specified | Nate assumes the fabric exists | ITSL's fabric = Talk + existing meetings. We add only the Monday digest post (§7.3) |

### 2.2 People ↔ their own agent

| Nate's mechanism | Substance | Our carrier |
|---|---|---|
| Private thread for authority | HUMAN HOLD questions (permissions, installs, accounts) answered ONLY in the owner's own session | Unchanged (BYGGPLAN §3.5). Widget lists open holds with "öppna din Claude Code-session" (§5) |
| Issue thread for work questions | AGENT BLOCKED = one specific question on the issue, publicly answerable | **Extended:** for takeover cards the question is *relayed to the origin card* so the human answers where she lives (§4, S2) |
| Boundaries contract | Ask-first list; "Manual only / after asking / automatically inside a workflow" | Unchanged (BYGGPLAN §7); takeover defaults to the most conservative tier (§3.4) |
| Maintenance loop | Trigger-driven (upstream change / scope creep / rising human cost / quiet failure), one agent one signal, keep/change/pause/retire | Unchanged (BYGGPLAN §8.5). Widget's presence dot + "failed" rows are where a non-technical owner first *sees* the trigger (§6 S6) |
| Memory governance | Agent-written memory = evidence until human confirms | Unchanged (M12 Brain Review) |
| **GAP 1** — nothing pushes to humans | Ledger/statuses are pull-only | **Closed:** NC notifications from @mentions + assignment (native), mirror status comment, widget counters, Talk pings (already in BYGGPLAN §1.2) |

### 2.3 People ↔ others' agents

| Nate's mechanism | Substance | Our carrier |
|---|---|---|
| "Assign cross-agent work to the human who owns the target agent" | The ONE extra team rule; eligibility quadruple-keyed | **Enforced mechanically:** when Fredrik assigns `Ada (agent)` on his card, the takeover service creates the engine card assigned to *sandra* with `[ada-claude]` in bracket 2. A human cannot get this wrong (§6 S4) |
| Cold-readability bar | "requester, outcome, sources, acceptance criteria, output location, boundaries, pause rule" | The enrichment run produces the full 8-section template; acceptance-tested (§3.3) |
| Presence check before handoff | "If the target agent is not online in the status ledger, say that" | Takeover service checks ledger heartbeat; stale/paused ⇒ near-miss notice to the requester (§6 S4) |
| **GAP 2** — who reviews Agent Review | Unspecified | **Closed by default rule:** reviewer = requester for takeover cards (she assigned, she judges), owner for native engine cards. §6 S3 |
| **GAP 3** — who may answer BLOCKED | Unspecified | **Closed by routing:** the question is @-relayed to the requester's origin card; anyone may still answer on the engine card (Nate-compatible), but the notification targets one person |

### 2.4 Agents ↔ agents

| Nate's mechanism | Substance | Our carrier |
|---|---|---|
| Claim lock | Status move + AGENT CLAIMED + re-read | **Stronger:** atomic `POST /claim` (BYGGPLAN §3.9), untouched by this proposal |
| Delegation + AGENT FOLLOW-UP | Agent routes a correctly-addressed issue to another human's agent; checks delegated issues every run | Unchanged. Takeover cards a human created via cross-assignment are *human* delegation, not agent delegation — no `delegated` label |
| Visible Delegation (same owner) | tmux, orchestrator runs the verification gates | Unchanged, interactive-session concern |
| Continuity via memory | Work-log handoff through OB1, not context | Unchanged (M12) |
| **GAP 6** — FOLLOW-UP has no consumer | Change-detection receipt, then nothing | **Partially closed:** for takeovers, the mirror IS the consumer — every engine-card state change updates the origin status comment, so the requesting human sees changes without polling |
| **GAP 7** — no SLAs/stale-claim reaping | Dead agent leaves a locked card | **Partially closed:** the 5-min reconciliation watchdog (§9) flags takeovers stuck >24 h in Working with a stale ledger heartbeat → notice to owner + Fredrik. Full SLA model deliberately not built (§10) |
| **GAP 10** — offboarding | Unspecified | `unenroll-board.sh` (ACL removal = clean revocation) + BYGGPLAN key rotation. Full offboarding runbook deferred |

**Net reading of Nate:** ownership is the load-bearing wall (one human per agent; all cross-boundary work routes through that human's queue), the two-channel pause split keeps authority questions private, and the issue is the only shared surface. This design changes NONE of that — it adds a human-side *rendering* of the same single source of truth, and mechanizes the two rules humans most often fumble (owner-addressing, cold-readable cards).

---

## 3. The intake mechanic: "Ge kortet till agenten"

### 3.1 The gesture (one, native, zero training)

> On any **enrolled** board, open the card → Assignees → pick **`Reb (agent)`** (bot user with a self-explanatory display name). Done.

That is the entire user manual. It works identically for:
- an existing card on your own board (S1),
- a brand-new card you just created anywhere on an enrolled board ("write a card, assign the agent"),
- another person's agent (S4 — pick `Ada (agent)` instead; the system handles owner-routing),
- mobile (Deck mobile app has the assignee picker).

Complementary paths unchanged from BYGGPLAN: `!queue` from Talk (→ Inbox), and interactive sessions creating protocol cards directly.

### 3.2 Event choice and latency

`agent_engine` is a PHP app **on the same Nextcloud instance** — it does not need `webhook_listeners` for intake. It registers in-process `IEventListener`s for Deck's card events (`OCA\Deck\Event\CardUpdatedEvent`; exact FQCN set for assignment changes verified at M4 — this is the same verification item BYGGPLAN M4 already carries for the event fan-out). In-process events fire synchronously with the user's save: **takeover phase 1 completes in seconds**, no 5-minute webhook lag, no version-uncertainty about `IWebhookCompatibleEvent`.

**Safety net (primary correctness mechanism, not just fallback):** a reconciliation job every 5 minutes ETag-polls all enrolled boards (`GET /boards/{b}/stacks`, 304s are cheap) and diffs *bot-assignments on cards* against the `takeovers` table. Missed event ⇒ takeover created late (≤5 min floor). Orphaned takeover (origin deleted, bot unassigned while app was down) ⇒ recall path (§6 S7). This makes the event listener a latency optimization and the poller the invariant.

**Latency budget, assign → AGENT CLAIMED:**

| Step | Mechanism | Typical |
|---|---|---|
| Assign → engine card exists (Inbox) + receipt comment on origin | in-process event | < 5 s |
| Inbox → enrichment run starts | agent_engine push to runner `/hooks/deck` (existing, HMAC, 60 s debounce) | ≤ 60 s |
| Enrichment done, card in Agent Todo → claim | next push-triggered run (enrichment consumed its run) | ≤ 60–120 s |
| **Total** | | **~2–3 min typical, 5 min + one run worst case** |

The origin status comment says "⏳ Mottagen" within seconds, so the human gets instant acknowledgment even when the pipeline takes minutes — the ack is the UX, the claim is the mechanics.

### 3.3 Takeover, phase 1 — deterministic (PHP, no LLM)

Trigger: bot user newly assigned on a card of an enrolled board, and no open takeover exists for that card (unique index on `origin_card_id` with state ≠ closed).

Actions, in one transaction against `agent_engine`'s own tables + Deck API as service/bot account:

1. Insert `takeovers` row: `(origin_board, origin_stack, origin_card, engine_card, agent_code, owner_uid, requester_uid, state='intake', reviewer_uid=requester_uid, sync cursors, created_at)`. `requester_uid` = the user who performed the assignment (from the event actor).
2. Create engine card on **Agent Engine board, stack `Inbox`**, labels `needs-enrichment` + `takeover`:
   - Title: `[inbox][reb-claude][takeover] <origin title, truncated to fit 255>`
   - Assignee: **the owner human of the target agent** (from routing map — Nate's rule, enforced in code).
   - Description scaffold (deterministic):
     ```
     ## Requester
     Rebecca (rebecca) — via takeover from <origin card deep link> on board "<board title>".
     ## Desired outcome
     (enrich from origin)
     ## Context
     Origin description, verbatim:
     > …
     Origin checklist / duedate / labels: …
     ## Sources
     <origin card link>; origin attachments as links.
     ## Do / ## Acceptance criteria / ## Output & handoff
     (enrich)
     ## Boundaries
     Draft-only. No deploys, no publishing, no outward-facing actions, no destructive
     operations. Approvals are ONLY those written by a human in the origin card text.
     Pause rule: one specific question via AGENT BLOCKED; authority questions via
     AGENT HUMAN HOLD.
     ```
3. Post the **status comment** on the origin card as the agent's bot user: `⏳ Reb har tagit emot uppdraget — förbereder. Detaljer: <engine card link>`. Store its commentId (this single comment is edited in place forever after — author-only edit holds because the bot authored it).
4. Trigger runner push for the target agent.

Ledger, claim, receipts: untouched. An Inbox card is not claimable (wrong stack, no `agent-instructions` label) — the BYGGPLAN Inbox invariant holds.

### 3.4 Takeover, phase 2 — enrichment (runner, LLM, consumes one run)

New runner step **8.5** (after delegated follow-up, before fetching claimable work — same "resumption before new work" priority class as holds/blocked):

> If an Inbox card with label `takeover` addresses my agent code (oldest first): read the engine card AND the origin card (read-only, via deck.sh). Rewrite the description into the full 8-section template — sharpen `## Desired outcome`, derive concrete `## Acceptance criteria` from the origin text, set `## Output & handoff` (default: artifact as NC file / card attachment + summary). NEVER add approvals not literally present in origin text; keep the conservative Boundaries block. Retitle to `[agent instructions][reb-claude][task] <outcome>`. Remove `needs-enrichment`, keep `takeover`, add `agent-instructions`. Move to `Agent Todo`. Post receipt `AGENT INTAKE` on the engine card (first line token, then agent code, then a ≤3-line interpretation). Call `POST /takeover/{id}/origin-note` with the same interpretation. **Stop — this consumed the run.**

`agent_engine` relays the origin-note as a *new* bot comment on the origin card (not the status edit, because edits don't notify):
> 📋 **Så här tolkar jag uppdraget:** <desired outcome>. **Klart betyder:** <criteria, compressed>. Jag börjar strax — kommentera här om något är fel. @rebecca

Two protocol notes:
- **The LLM never writes to human boards directly.** All origin-card writes go through the narrow `origin-note` relay endpoint (rate-limited: one per takeover state, length-capped ≤ 900 chars) executed by deterministic PHP as the bot user. The runner's tool allowlist stays as in BYGGPLAN §5.2.
- **Enrichment-consumes-a-run** is a declared deviation of the same class as "a resumed issue consumes the run" — paused/incoming work outranks new work, one unit per run, stop after it. One-task-per-run semantics survive.

If the origin card is too thin to enrich responsibly (no discernible outcome), the runner does NOT guess: it posts `AGENT BLOCKED` + one specific question on the engine card, relayed to the origin card (S2 mechanics), card → `Agent Needs Input`. The human answers on her own card; enrichment completes next run.

### 3.5 From here on: pure Nate

Once in `Agent Todo` the card is indistinguishable from a hand-written protocol card: quadruple-keyed eligibility, oldest-first, atomic claim (200/409), `AGENT CLAIMED`, re-read after claim, recall-before-work, one card per run, full receipt vocabulary, ledger updates, `Agent Done`/`Agent Review` fork. The `takeover` label's only runtime meaning is: `agent_engine`'s event fan-out *additionally* drives the mirror (§4).

---

## 4. Two-way sync contract (origin card ↔ engine card)

One `takeovers` row per pair; `agent_engine` is the only writer of mirror traffic; all mirror writes are authored by bot accounts.

### 4.1 Engine → origin (protocol state, rendered as Swedish)

| Engine event (via existing fan-out) | Origin effect |
|---|---|
| Card → Agent Todo (post-enrichment) | Status edit: `📋 I kö — <interpretation link>`. Plus the one-time interpretation comment (§3.4) |
| `AGENT CLAIMED` | Status edit: `🔵 Arbetar — startade 10:32` |
| `AGENT BLOCKED` | Status edit `🟡 Väntar på svar från dig` **+ new bot comment**: `❓ <the one specific question> — svara här i en kommentar. @<requester>` (native NC notification incl. mobile) |
| `AGENT HUMAN HOLD` | Status edit `🟡 Väntar på ägarens godkännande (<owner>)`; the hold question itself goes to the OWNER via BYGGPLAN §3.5 (Talk + NC notis), never onto the origin card — the two-channel split is preserved across the mirror |
| `AGENT RESUMED` | Status edit `🔵 Arbetar igen` |
| `AGENT DONE` → Agent Review | Status edit `🟠 Klar för granskning` **+ new bot comment**: `✅ Klart för din granskning: <1-line result + artifact link>. Svara **ok** för att godkänna, eller skriv vad som ska ändras. @<reviewer>` |
| Approved → Agent Done | Status edit `✅ Klar — <1-line result>` + per-board `on_done` action (§7.1): `comment_only` (default) or `move_to_stack:<id>` on the origin board |
| `AGENT FAILED` | Status edit `🔴 Gick inte — <last safe step>. <owner> är notifierad.` + NC notification to owner AND requester |
| Recall confirmed | Status edit `⏹ Återkallad` |

Rules: exactly ONE status comment, edited in place (≤ 900 chars, always ends with the engine-card link). New comments are posted only at the three action moments (interpretation, question, review) because *comment edits don't notify* — every mirror comment that needs action carries an @mention, which is what makes NC's bell/push/email fire. Receipt tokens never appear on origin cards.

### 4.2 Origin → engine (human input flows back)

| Origin event | Engine effect |
|---|---|
| New comment by a **human** (any non-bot) | Mirrored to engine card: `[från ursprungskortet — Rebecca] <text>` (truncated at 950 chars + "… läs hela: <link>"). If takeover state = blocked, this IS the answer — the runner's step 7 finds it on re-read and posts `AGENT UNBLOCKED`/`AGENT RESUMED`. If state = review and author = reviewer: parsed as verdict (§6 S3). Otherwise: context for next resume/claim |
| Origin description/title edited while state ∈ {working, blocked, review} | **No rewrite of the engine template** (the claimed card is the contract). Instead: bot comment on engine card `ORIGIN EDITED: <compact diff summary>` + NC notification to requester: "Du ändrade ursprungskortet medan agenten arbetar — agenten ser ändringen som en kommentar, inte som ett nytt kontrakt. Vill du börja om: ta bort och lägg tillbaka agenten." Honest, loop-free |
| Origin edited while state = intake/queued (not yet claimed) | Engine card re-scaffolded/re-enriched (safe — nobody claimed it) |
| Bot unassigned / origin archived / origin marked done / origin deleted | Recall (§6 S7) |
| Second bot assigned on the same origin card | No second takeover (unique index). Near-miss notice to the assigner: "Kortet är redan hos Reb — ta bort en av agenterna. Vill du byta agent: ta bort Reb först." |

### 4.3 What deliberately does NOT sync

- **Labels** (board-scoped, semantics differ per board) — never synced.
- **Checklists, attachments, duedates** — referenced by link in the scaffold at intake; not live-synced. Duedate exception: origin duedate is copied once into the engine card at intake (visible to the agent as context, not as an SLA).
- **Receipt comments** — engine-only.
- **Comments by other humans on the ENGINE card** — not mirrored back to origin (the origin is the requester's channel; engine-side discussion is team-protocol space). Anyone Nate-style answering BLOCKED directly on the engine card still works — the runner reads the engine card, not the mirror.
- **Stack positions** — origin stays wherever the human keeps it (their column semantics are theirs).

### 4.4 Loop prevention

Structural, not heuristic: the mirror service **ignores every comment/change authored by any member of `agents-bots`** on both sides, and its own service account. All mirror writes are bot-authored ⇒ they can never re-trigger mirroring. Per-direction monotonic cursors (`last_mirrored_comment_id` origin→engine and engine→origin) make reconciliation idempotent; the status comment is one known commentId, edited in place. The `takeovers` row is the single lock: state transitions are guarded in the same DB transaction pattern as the claim endpoint.

---

## 5. The thin UI: dashboard widget "Min agent"

One NC Dashboard `IWidget` inside `agent_engine` (Deck itself ships one — pattern proven on-instance; itsl-surfaces option C, 2–4 dev-days). hubs_start idiom: counters → short list → one action per row. All rows deep-link to Deck cards or origin cards — **the widget is a router, never a workspace.**

```
┌─ Min agent — Reb ● online (senaste körning 09:42) ──────────┐
│  [ Väntar på dig: 2 ]  [ Arbetar: 1 ]  [ I kö: 3 ]  [ Klart idag: 4 ] │
│                                                              │
│  ❓ Uppdatera kunddokumentationen — Reb har en fråga         │
│     「 Svara 」→ öppnar ditt kort (ursprungskortet)          │
│  🟠 Prisjämförelsen — klar för din granskning                │
│     「 Granska 」→ öppnar ditt kort med resultatlänken       │
│  🔒 AE-217 väntar på ditt godkännande (human hold)           │
│     「 Öppna din Claude Code-session för att svara 」         │
└──────────────────────────────────────────────────────────────┘
```

- **Presence header** from the ledger (`Last heartbeat`, `Automation state`): green = heartbeat < 2 intervals, yellow = stale, grey = paused, red = last result `failed`. This is the ONLY ledger rendering a non-technical human ever sees.
- **"Väntar på dig"** aggregates, for the logged-in user: takeovers in state blocked/review where `requester_uid = me`; engine cards assigned to me with label `blocked` or `human-hold`; `Agent Review` cards assigned to me. Sourced from `agent_engine`'s own tables + one ETag-cached Deck read — no new APIs.
- **Counters double as filters** (Dagspulsen pattern) expanding the list below; list caps at 7 rows.
- Cross-agent visibility: rows include things YOU requested from OTHERS' agents (requester-scoped), so Fredrik sees "Ada: klar för din granskning" on his own dashboard (S4).
- No Swedish protocol tokens anywhere in the widget; token vocabulary appears only on the engine board.

**Explicitly rejected UI** (and why): Vue full page (volume doesn't justify it yet — itsl-surfaces sequencing A→B→C→D holds), Deck card-sidebar plugin (no public extension point; forking Deck violates the ops posture), Smart Picker provider for task creation (the assignment gesture already covers "create from anywhere on a board"; picker adds a second mental model for zero new capability — revisit only if humans ask for task-creation from Text/Talk beyond `!queue`).

---

## 6. The seven scenarios, end-to-end

Conventions below: **R** = Rebecca (owner of Reb / `reb-claude`, bot `bot-reb` shown as "Reb (agent)"). Origin = card on the human board; engine = card on Agent Engine. Every notification named is a native NC notification (bell + mobile push + optional email, per user settings) unless marked Talk.

### S1 — Rebecca gives "Uppdatera kunddokumentationen" to Reb

1. **Click:** R opens her card on her board → Assignees → `Reb (agent)`. (1 gesture, 3 taps on mobile.)
2. **< 5 s (in-process event):** takeover phase 1. Engine card created in `Inbox` (`[inbox][reb-claude][takeover] Uppdatera kunddokumentationen`, assignee rebecca, labels `needs-enrichment`+`takeover`, scaffolded description). Origin gets bot status comment `⏳ Reb har tagit emot uppdraget — förbereder. <link>`. Runner push fired.
3. **≤ 60 s:** Reb's runner wakes (push), step 8.5: reads both cards, writes the 8-section template, retitles `[agent instructions][reb-claude][task] Uppdatera kunddokumentationen …`, labels → `agent-instructions`+`takeover`, moves to `Agent Todo`, posts `AGENT INTAKE` (engine) and the interpretation comment on origin: `📋 Så här tolkar jag uppdraget … Klart betyder … @rebecca`. **R gets a notification** — her first checkpoint; she can correct course by replying before/while work happens. Run stops. Status comment → `📋 I kö`.
4. **≤ 2 min:** next push-triggered run: preflight → holds → blocked → follow-ups → `GET /queue/reb-claude` → `POST /claim` 200 → card → `Agent Working`, `AGENT CLAIMED` posted, ledger `claimed AE-n`. Mirror: status edit `🔵 Arbetar — startade 10:32`. Runner re-reads card, recalls from brain_reb + team brain, does ONLY the scoped work, writes the draft to an NC file attached to the engine card.
5. **Done:** documentation updates are outward-ish → judgment required ⇒ `AGENT DONE` + card → **`Agent Review`**, ledger `completed AE-n`, writeback thought to brain_reb. Mirror: status `🟠 Klar för granskning` + comment `✅ Klart för din granskning: utkast här <link>. Svara **ok** för att godkänna, eller skriv vad som ska ändras. @rebecca` → **notification**.
6. **How she sees status all along:** (a) the status comment on HER card, (b) widget counters ("Arbetar: 1" → "Väntar på dig: 1"), (c) the engine-card link if she ever wants the receipt trail. She never opens the engine board unless curious.
7. **How she knows it's done-done:** she replies `ok` on her card → `agent_engine` (reviewer-comment parser) moves engine card → `Agent Done`, sets Deck `done`, status edit `✅ Klar — kunddokumentationen uppdaterad (v2.4)`, per-board `on_done` action (e.g. origin auto-moves to her "Klart" column if enrolled so). Widget "Klart idag: +1".

State summary — origin: unchanged column (or configured move), 1 status comment (edited 5×), 2 action comments, bot assigned throughout. Engine: Inbox → Agent Todo → Agent Working → Agent Review → Agent Done; receipts `AGENT INTAKE`, `AGENT CLAIMED`, `AGENT DONE`; ledger heartbeats + `claimed`/`completed`.

### S2 — Reb hits a wall (AGENT BLOCKED)

1. Mid-run, Reb lacks a fact that belongs on the card (say: which product versions the docs must cover). Runner posts `AGENT BLOCKED` + ONE specific question on the engine card, moves it → `Agent Needs Input`, label `blocked`, ledger `blocked AE-n`, stops.
2. **Seconds later** (fan-out): origin status edit `🟡 Väntar på svar från dig` + bot comment `❓ Vilka produktversioner ska dokumentationen täcka — bara 1.3 eller även 1.2? Svara här i en kommentar. @rebecca` → **notification on her phone**. Widget: "Väntar på dig: 1" with a `Svara`-row deep-linking to her own card. (Fastest path to the human = native push on an @mention, zero built infrastructure.)
3. **She answers WHERE SHE LIVES:** a plain comment on her own card: "Bara 1.3." Mirror (in-process event, seconds): appears on engine card as `[från ursprungskortet — Rebecca] Bara 1.3.` + runner push.
4. **Resume:** next run, step 7 (blocked sweep — before any new work): answer present on the card ⇒ `AGENT UNBLOCKED` + `AGENT RESUMED`, card → `Agent Working`, label `blocked` removed, ledger `resumed AE-n`, work completes, S1 step 5 onward. Status comment: `🔵 Arbetar igen` → `🟠 Klar för granskning`.

Nate fidelity: the answer *does* land on the engine card (mirrored), which is exactly his "Resume only after the missing answer appears on the same issue" — the mirror just saved Rebecca the trip. A teammate could equally answer directly on the engine card; both work. If the question is an authority question (permissions, accounts), the runner uses `AGENT HUMAN HOLD` instead and the question goes ONLY to Rebecca's private session via BYGGPLAN §3.5 — the origin card shows only `🟡 Väntar på ägarens godkännande`, preserving the public/private split.

### S3 — Agent Review: Mattias reviews Marvin's work

Case A — takeover card (Mattias assigned `Marvin (agent)` on his own card):
1. Marvin finishes → `AGENT DONE` → `Agent Review`. Origin comment: `✅ Klart för din granskning: <result + artifact link>. Svara **ok** för att godkänna, eller skriv vad som ska ändras. @mattias` → notification. Widget row `🟠 Granska`.
2. **Approve:** Mattias reads the artifact (link opens the NC file / card attachment), replies `ok` on his card. `agent_engine` (only the designated reviewer's comments are parsed as verdicts; matching: trimmed, case-insensitive, starts with "ok"/"godkän") moves engine card → `Agent Done`, sets `done`, status `✅`, per-board on_done. No receipt token — human approval is a human act; the audit trail is the reviewer's own comment (mirrored to the engine card with attribution).
3. **Reject/rework:** he replies anything else, e.g. "Tabellen i avsnitt 3 är fel — använd siffrorna från Q2-rapporten." `agent_engine`: mirrors the feedback to the engine card as `REWORK REQUESTED (cycle 1/3) — [från ursprungskortet — Mattias] …`, moves engine card `Agent Review` → **`Agent Todo`** (eligibility intact: stack/label/title/assignee all still valid), runner push. Next run claims it again (`AGENT CLAIMED`), re-reads — the feedback is on the card — fixes, `AGENT DONE` → `Agent Review` again. Status comment cycles `🔵`→`🟠`. After 3 cycles: stays in Review + notification "ta det i din interaktiva session — kortet har snurrat 3 varv." (Bounded loops; GAP 2's missing rework receipt closed with a plain, greppable convention comment, not a new token.)
4. Case B — native engine card (no origin): identical, except the review comment/`ok` happens on the ENGINE card, or Mattias simply **drags the card to Agent Done** — the native gesture is always available to humans and `agent_engine` treats a human-performed move out of Review as the verdict (move to Done = approve; move to Todo = rework). Reviewer default: card's assignee (owner). The requester may be named as reviewer in `## Output & handoff`.

Silence is not consent throughout: nothing ever auto-leaves `Agent Review` (BYGGPLAN §3.1 rule untouched); the widget keeps nagging via the counter, and the Friday ritual (S6) empties it.

### S4 — Cross-delegation: Fredrik → Ada (Sandra's agent)

1. **Click:** Fredrik, on HIS board (or any enrolled board), creates/opens the card "Jämför prismodeller för lagringstjänsten" → Assignees → `Ada (agent)`.
2. **Takeover phase 1 with routing enforcement:** `agent_engine` looks up `ada-claude` in the routing map → owner = sandra. Engine card is created **assigned to `sandra`** (Nate: "assign cross-agent work to the human who owns the target agent — not to yourself"), bracket 2 = `ada-claude`, `## Requester: Fredrik (fredrik) — via takeover from <link>`. Fredrik could not have addressed it wrong: the gesture encodes the target agent, the system derives the owner. Reviewer = requester = Fredrik.
3. **Presence check (Nate's rule, mechanized):** the service reads Ada's `AGENT STATUS` ledger fields. If `Last heartbeat` is stale (> 2 intervals) or `Automation state: paused` → **near-miss notice to Fredrik** (NC + Talk, same channel as BYGGPLAN §3.2's detector): "Ada har inte kört sedan i går 16:40 — kortet ligger i kö men plockas inte förrän hon är igång. Sandra är notifierad." Sandra also gets the native assignment notification either way — the owner always knows what enters her agent's queue.
4. **Near-miss net (general):** if anything about the produced card were ineligible (it can't be, by construction — but e.g. an admin later strips the label), the existing detector fires to the card's assignee. The detector also covers hand-written cross-cards people still create directly on the engine board (M7's test case).
5. **Sandra's role:** none required for the run itself (the queue is asynchronous); her control points are: the assignment notification, her widget ("I kö hos Ada: 1 — begärd av Fredrik"), and her standing authority — she can pause her agent, or answer holds if the task trips one. Her ownership is never bypassed: the card sits in HER queue under HER agent's boundaries.
6. **Run:** Ada claims (`AGENT CLAIMED`), works, `AGENT DONE` → `Agent Review`. Mirror goes to **Fredrik's** origin card (requester): `✅ Klart för din granskning … @fredrik`. Fredrik reviews (S3 mechanics). Fredrik's widget carried the whole lifecycle under "Väntar på dig"/"Begärt av dig"; Sandra's widget showed it under her agent's activity.
7. If Ada gets BLOCKED: the question relays to Fredrik's origin card (requester has the domain answer). If Ada needs a HOLD (authority): it goes to **Sandra's** session — authority follows ownership, content follows the requester. This is exactly Nate's two-channel split applied across the delegation.

### S5 — Daily overview for a non-technical person

Morning, zero navigation: NC dashboard is the start page → **"Min agent"** widget.
- "Vad gjorde min agent?" — `Klart idag: 4` (click → the four cards, each with a 1-line result), presence dot green, "senaste körning 09:42".
- "Vad väntar på MIG?" — `Väntar på dig: 2`, two rows, each with ONE verb: `Svara` / `Granska` / `Öppna din session` (hold). Rows deep-link to *her own cards* where possible.
- Ambient, no widget needed: every action moment already reached her phone via native @mention notifications; her own board shows live status comments on every delegated card.
- Optional (already in BYGGPLAN's Talk footprint): a `daglig digest`-post 16:30 in each capture room — "Reb idag: 4 klara, 1 väntar på dig" — one scheduled `agent_engine` job posting via the capture bot. Push-only complement for people who live in Talk. (Small; ships M8-ish, not on the critical path.)
- What she never needs: the engine board, the ledger, receipt tokens, `engine_meta`, any CLI.

### S6 — Team rituals

- **Monday sync (15 min, all four):** `agent_engine` cron posts at 08:30 to `Agent Ops` (Talk): per-agent 7-day table from `engine_meta.runs` (`completed/blocked/holding/failed` counts + spend line + oldest item in Review). The meeting reads the post, not a dashboard; decisions (retire a card type, adjust routing map, bump a standing version) are made by humans and written to the standing cards — version-diff preflight propagates them (Nate §6, unchanged).
- **Friday 10 min/person:** empty YOUR widget — `Väntar på dig` → 0 (answer blocked, review Review, approve holds); from M12 also the Brain Review pending queue (BYGGPLAN §8). The widget makes the ritual measurable: the counter is the exit criterion.
- **Who looks at the ledger, when:** agents — every run (write). The widget — every render (read: presence + last result). Humans directly — only two cases: Fredrik (or any owner) debugging a dead handoff ("check routing map, heartbeat, label, title, status" — Nate's own troubleshooting order), and the maintenance loop's evidence reading. Non-technical members: never; the presence dot IS their ledger.
- **Maintenance loop:** trigger-driven per BYGGPLAN §8.5 (upstream change / scope creep / rising human cost / quiet failure). The widget surfaces two triggers early: a red presence dot (quiet failure) and a chronically full "Väntar på dig" (rising human cost). PII spot-check Fridays from M11 (unchanged).
- **Standing updates:** unchanged Nate — edit the standing card, bump version + changelog; every runner preflights the diff and leaves `AGENT APPLIED`. Humans see nothing unless a near-miss/notice fires. (GAP 4 — governance of mandatory context — is consciously accepted at 4-person scale: standing edits are Fredrik-by-convention; revisit at 8+ people.)

### S7 — Human takes the task back / agent proposes takeovers

**Recall (human finishes it herself, or aborts):**
1. **Gesture — symmetric to intake:** remove `Reb (agent)` from the origin card's assignees. (Equivalents, all detected: archiving the origin card, marking it done, deleting it, or replying `avbryt` to the status thread — the comment parser accepts it as a recall verb.)
2. `agent_engine` on the event (or the 5-min reconciler), by engine-card state:
   - **Inbox / Agent Todo (not claimed):** archive the engine card + bot comment `Recalled by rebecca before claim` (plain comment, not a token — no agent was involved). Status edit `⏹ Återkallad`. Takeover row → closed. Cost: zero agent work wasted.
   - **Agent Working (claimed, possibly mid-run):** set `recall_requested` on the row + bot comment `RECALL REQUESTED by rebecca` on the engine card. Contract, honestly communicated in the status edit: *"⏹ Återkallas — pågående körning (max några minuter) avbryts inte halvvägs; det som hunnits görs sparas."* The run is short-bounded (one card, `--max-turns 40`); the wrapper checks the flag before starting any run and skips recalled cards; after the in-flight run ends (DONE/BLOCKED/FAILED — whatever it was), `agent_engine` archives the engine card regardless and final-edits the status: `⏹ Återkallad — agenten hann: <last receipt line>; utkast bevarat: <link>`. Ledger keeps the truthful last result. No half-broken shared state: agent work products were drafts by boundary-default, so "human finishes it herself" just means she uses or ignores the draft.
   - **Agent Needs Input / Agent Review:** immediate archive + `⏹` status. Anything the agent produced stays linked.
3. She then does the task herself on her own card as if the agent had never existed. Re-delegation later = assign the bot again (a NEW takeover row; the old engine card's receipt history remains in the archive — the audit trail survives recalls).

**Reverse — may an agent propose to take something from a human board? (Får den?)**
- **Autonomously grab: NO — structurally.** The headless runner's boundaries and tool surface (BYGGPLAN §5.2/§7) do not include writes to human boards; the only origin-card write path is the relay endpoint, which requires an open takeover row. An agent cannot create a takeover; only a human assignment (or the reconciler confirming one) can. This is Nate's ownership wall: work enters an agent's queue only via a human act.
- **Propose: YES, in the human's own interactive session.** Reb has read access to enrolled boards (bot ACL membership). In Rebecca's Claude Code session: "titta på min tavla — vad borde du ta?" → the agent reads her board, suggests 2–3 cards with reasons ("kortet 'Städa release-notes' liknar AE-118 som jag klarade förra veckan"), and SHE performs the assignment (or tells the session to do it *as her*, via her personal app password — a human-authorized act, attributable to her). The session-preflight of `open-agent-engine` may include one such suggestion line when the board has obvious candidates.
- **Not built (deliberately):** widget "suggestion slot" and any scheduled scan that pings humans with takeover proposals. Unsolicited agent-initiated pings about *their own* boards is exactly the trust-burning noise the near-miss detector avoids by being purely mechanical. Revisit only after M11 proves the pull-based version is missed.

---

## 7. Provisioning

### 7.1 Board enrollment (`scripts/enroll-board.sh <boardId> [--bots reb,atlas,ada,marvin] [--on-done comment_only|move_to_stack:<stackId>]`)

Idempotent, per human board:
1. Add the selected bot users to the board ACL (`POST /boards/{id}/acl`, permission: edit — required to be assignable, comment, and edit own comments; not manage/share). Per-bot, not the `agents-bots` group, so a personal board can enroll only its owner's agent.
2. Register the board in `agent_engine.enrolled_boards` (boardId, allowed bot set, on_done behavior, enrolled_by, enrolled_at).
3. **Zero footprint on the human board:** no stacks, no labels, no cards created. The gesture is assignment; the mirror is comments. Un-enrollment = ACL removal + row disable — clean revocation (also the offboarding primitive, GAP 10).
4. **Enrollment checklist (human step, PII rule):** enrolled boards must be internal work boards — no client/case PII in card titles/descriptions, because bots (and thus runner context) can read the whole board. Recorded per board at enrollment (`pii_reviewed_by`). The brain firewall (BYGGPLAN §2.3) remains the second net; `safeRef()` logging discipline applies to all takeover logs.

### 7.2 Engine-board additions (extends `deck-bootstrap.mjs`)

- New label on Agent Engine board only: **`takeover`** (joins `agent-instructions`, `blocked`, `human-hold`, `delegated`, `needs-enrichment`).
- Bot display names set at user provisioning: `Reb (agent)`, `Atlas (agent)`, `Ada (agent)`, `Marvin (agent)` — the assignee picker is the UI; the name must say what it is. Lock these at M0 alongside the other names.

### 7.3 agent_engine additions (server)

| Piece | Size | Notes |
|---|---|---|
| `takeovers` table + state machine | small | states: intake → queued → working → blocked/holding → review → done / recalled / orphaned; unique open-takeover per origin card |
| Deck event listeners (in-process) + 5-min reconciler | small | reconciler is the correctness floor; listener is the latency path |
| Mirror service (status-edit + action comments + comment relay + verdict/recall parser) | the core | all writes as bot users; ignores bot-authored input; per-direction cursors |
| `POST /takeover/{id}/origin-note` (runner relay) | tiny | rate-limited, length-capped; the ONLY LLM→human-board path |
| Runner prompt step 8.5 (enrichment) + `AGENT INTAKE` | prompt + docs | consumes the run |
| Dashboard widget `IWidget` + small JS panel | 2–4 dev-days | data from takeovers + ledger + one ETag Deck read |
| Monday digest + optional daily digest jobs | tiny | posts via existing Talk bot |

Milestone fit: takeover core + mirror land as **M4.5** (after `agent_engine` v1, before M5 smoke tests extend to takeover scenarios); widget at **M7** (with team onboarding — it is the adoption instrument); digests at **M8**. New permanent smoke tests: assign→claim end-to-end unattended; blocked→origin-answer→resume unattended; recall in each state; double-assign near-miss; reviewer `ok` and rework cycle; mirror-loop test (bot comment storm must NOT self-amplify); reconciler catch-up after a forced missed event.

---

## 8. Protocol fidelity statement (what may not break, and doesn't)

| Nate invariant | Status |
|---|---|
| Title grammar `[agent instructions][<code>][task]` | Intact on all claimable cards; `[inbox][…][takeover]` exists only pre-eligibility, same class as BYGGPLAN's `[inbox]` |
| Quadruple-keyed eligibility; assignee = owner human | Intact — takeover *computes* the owner from the routing map; humans can no longer mis-address |
| Atomic claim, 200/409, re-read after claim | Untouched (takeover produces cards; it never claims) |
| One task per run; paused work outranks new work | Intact; **declared deviation:** an enrichment (step 8.5) consumes a run, same class as resume-consumes-run |
| 16-token receipt vocabulary | Intact + **one declared ITSL token: `AGENT INTAKE`** (posted by the runner after enrichment). Recall/review-verdict traffic is human/service commentary, not agent receipts — no tokens invented for them |
| Two pause channels (BLOCKED public / HOLD private) | Intact — the mirror relays BLOCKED to the requester's card (still lands on the engine card, Nate's resume condition unchanged); HOLD never touches the origin card |
| Ledger: one comment, in-place, machine tokens | Untouched; widget only reads it |
| Cold-readability of routed cards | Enforced by the enrichment acceptance criteria; thin origins produce a BLOCKED question, never a guessed contract |
| Boundaries / approval-in-issue-text | Strengthened: takeover cards default to draft-only; approvals only from literal human text on the origin |
| Standing context, versions, APPLIED, skill subscription | Untouched |
| "Route to the owner, address the agent" | Mechanized (§6 S4) |

---

## 9. Failure modes

| Failure | Handling |
|---|---|
| Deck event missed (listener bug, app disabled, race) | 5-min reconciler diffs bot-assignments vs takeovers; creates late takeovers, closes orphans. Worst-case intake latency = 5 min + one run; status comment always eventually correct |
| `agent_engine` down | Assignments queue up on boards; reconciler catches up at restart. Runner falls back to BYGGPLAN §3.9 degraded mode for claims; takeovers simply pause (no corruption — the table is the truth) |
| Human edits origin mid-flight | No template rewrite after claim; diff-notice comment on engine card + honest notification to the requester (§4.2). Pre-claim edits re-enrich safely |
| Origin card deleted while working | Takeover → orphaned; engine card commented + kept (work finishes; result delivered via NC notification to requester since no origin remains); owner notified |
| Engine card manually deleted/moved by a human | Reconciler detects; status comment on origin: "kopplingen bröts — <who/what>"; takeover closed; near-miss notice to owner |
| Mirror comment > 1000 chars | Truncate at 950 + "läs hela: <link>" (both directions) |
| Two bots assigned to one origin card | First event wins; second → near-miss notice to assigner; unique index guarantees one open takeover |
| Reviewer replies ambiguous text ("ok men fixa rubriken") | Parser is conservative: verdict only when the comment *starts* with an approval verb AND is ≤ 1 short sentence; anything else = rework feedback (safe default — worst case one extra cycle, never a false approval) |
| Rework loop | Hard cap 3 cycles → parked in Review + "take it interactive" notification |
| Agent dies after claim (stale lock) | Watchdog: takeover in `working` > 24 h with stale ledger heartbeat → notification to owner + Fredrik; recovery per Nate = human triage (`AGENT FAILED` or manual re-queue). No auto-reaping (deliberate — GAP 7 partially open) |
| Notification fatigue | Mirror posts at most 3 action comments per takeover lifecycle (interpretation, question, review); everything else is in-place status edits (no notifications). Digest jobs are opt-in per room |
| Bot account compromised | Bot is in `agents-bots` only: engine board + enrolled boards, zero case folders, zero admin (BYGGPLAN §7). Per-bot app-password revocation; un-enrollment removes board access |

---

## 10. Deliberately NOT built (angle discipline)

1. **No full Vue page / portal** (itsl-surfaces option D) — the widget + native boards must prove insufficient first (M11 gate data will tell).
2. **No Deck fork or card-sidebar extension** — no public extension point; forking breaks the ops posture (Risk #1).
3. **No Smart Picker / Talk command DSL beyond `!queue`** — one intake mental model (assign the agent) + one mobile quick path (`!queue`).
4. **No custom push infrastructure** — native NC notifications (@mention + assignment) are the pager; no Telegram/Discord/e-mail senders.
5. **No autonomous agent-initiated takeover proposals** — propose-only in interactive sessions (§6 S7); no scheduled board-scanning pings.
6. **No live sync of checklists/attachments/labels/duedates** — link-based referencing; live field sync is a correctness swamp for marginal value.
7. **No SLA/escalation engine, no auto-reaping of stale claims** — watchdog notices only; humans triage (GAP 7 stays consciously half-open at this scale).
8. **No review workflow app** — review = reply `ok` (or drag the card); no approval buttons, forms, or e-signatures.
9. **No per-user settings UI** — enrollment and on_done behavior via script/occ; four users don't need admin screens.
10. **No team analytics dashboard** — Monday digest post + `engine_meta` SQL.
11. **No changes to the engine board's protocol surface** — no Swedish tokens, no extra stacks, no per-agent labels.

---

## 11. Summary of the contract, in one table

| Question | Answer |
|---|---|
| How does a human give work to an agent? | Assign `X (agent)` on any card on an enrolled board — including someone else's agent |
| Who formats it for the protocol? | The agent itself (enrichment run, `AGENT INTAKE`), never the human |
| Where does the human follow status? | Her own card (one live status comment) + "Min agent" widget |
| Where does she answer questions? | Her own card, plain comments (mirrored to the engine card = Nate's resume condition) |
| Where does she approve? | Reply `ok` on her own card, or drag the engine card (power users) |
| Where do authority questions go? | Owner's private Claude Code session — never the origin card |
| What does she never see? | Tokens, ledger, title grammar, the engine board (unless curious) |
| What does the protocol never see? | Any weakening: claim, receipts, eligibility, one-task-per-run, boundaries all verbatim; +1 token, +1 label, enrichment-consumes-a-run — all declared |
