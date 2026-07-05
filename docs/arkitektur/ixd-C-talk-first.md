# IxD Proposal C — Conversation-First: Talk is the interface, Deck is the ledger

**Angle:** The primary human surface is **the personal agent room in Nextcloud Talk**. Humans delegate, answer blockers, approve reviews, and read digests by *talking to their agent* — from a phone, in seconds. Deck remains the **ledger/protocol surface**: the Agent Engine board carries the verbatim Nate protocol (statuses, title grammar, 16 receipt tokens, ledger, claim lock) and is the single source of truth, but humans rarely open it. The humans' own existing Deck boards stay exactly as they are; a **takeover bridge** lets a human hand any card on their board to their agent with one gesture, and keeps the origin card honestly updated while the work happens on the engine board.

Date: 2026-07-04. Builds strictly ON TOP of BYGGPLAN.md (nothing in it is broken; §9 lists the deltas). Grounded in `nate-team-model.md`, `itsl-surfaces.md`, `deck-capabilities.md`.

**One sentence per requirement:**
- *Fredrik's req 1 (smooth Deck interaction, no protocol learning):* humans never write title grammar, never post tokens, never touch the engine board — the bridge and the agent do all protocol work; the human's only Deck act is a native gesture on their own board.
- *Fredrik's req 2 (assign → agent moves it to its own board):* implemented literally, as the **takeover pipeline** (§4): assign the bot user on the origin card → engine card created and enriched → origin card mirrored until done.
- *Fredrik's req 3 (how Nate intends the team to collaborate):* §2 is the detailed analysis, with every design decision here traced to a Nate rule or an explicitly named Nate GAP.

---

## 1. Design stance

Three axioms drive everything:

1. **Talk = control surface, Deck = truth.** Every human decision (delegate, answer, approve, reclaim) can be expressed as a short Talk message; every such message is translated by the system into protocol-correct Deck state. The reverse also holds: if Talk is down, every action remains possible directly in Deck — the conversational layer is a *projection with write-through*, never a second source of truth.
2. **Humans speak human; agents speak protocol.** The 16-token vocabulary, the title grammar, the 8-section task template, the ledger format — all of it is produced and consumed by `agent_engine`, the runner, and the skills. A human sees Swedish sentences and their own card titles. This is how Fredrik's "must not require humans to learn the agent protocol" is satisfied without weakening the protocol by a single token.
3. **Phone-first latency.** The whole loop — hand over a card, answer a blocker, approve a review — must be doable from a phone in under a minute per interaction. Concretely: gesture → agent's first Talk message ≤ 30 s; "kör" → `AGENT CLAIMED` ≤ 60 s; blocker answer → `AGENT RESUMED` ≤ 90 s. All three are met by in-process Deck events + the existing push-to-runner fan-out (BYGGPLAN §5.2), not by webhook_listeners' 5-minute background-job path.

---

## 2. Detailed analysis: how Nate intends the team to collaborate — and what this design adds

### 2.1 People ↔ people (Nate: thin by design)

Nate specifies almost no human-to-human protocol. What exists: (a) **naming decisions are a team meeting, made first** ("most failed first runs come from mismatched team names, labels, statuses, issue titles, or agent codes"); (b) the **routing map** is the org-chart artifact humans agree on and maintain; (c) onboarding is **person-to-person, serialized**; (d) every task embeds `requester` — the escape hatch back to human conversation; (e) **"Human approval is required for publishing and customer-facing changes"**, granted issue-level, visible to all; (f) the stakeholder-update skill formalizes outbound comms ("only when shipped, only what's true"). Nate explicitly *assumes* the team already has a human communication fabric (his GAP 12).

**What this design does with it:** ITSL's human fabric IS Nextcloud Talk — so we make the assumed fabric and the agent fabric the same rooms. People-to-people conversation about a task happens in the same threads where the task's agent events land, so context never has to be re-established. The routing map and naming decisions remain M0 meeting outputs exactly as BYGGPLAN prescribes.

### 2.2 People ↔ their own agent (Nate: the richest channel)

Nate's structure: a **private channel** ("the human's own agent thread/app") for everything about the runtime itself — permissions, installs, account authority, skill approvals (approval creates a subscription; scope expansion re-asks); a **contract** the human writes (local context file + private setup issue + ask-first boundary); **task interaction** via issues (file → runner claims oldest eligible, one per run; answer BLOCKED on the issue, HOLD in the private thread; review Agent Review items); **maintenance** as a trigger-driven human ritual; **memory governance** where "agent-written memory starts as evidence, not instruction."

**The load-bearing move of this proposal:** Nate's phrase is "the human's own agent thread/**app**". We declare the **personal Talk agent room** (the existing capture room from BYGGPLAN §4.1, upgraded to two-way) to be exactly that thread/app. It already has the right properties: private (one human + the bot), authenticated (Talk HMAC verifies the sender; room membership is provisioned), mobile, and persistent. So:
- HOLD questions are *pushed* there (BYGGPLAN §3.5 already pings there) and — new — simple authority-grant holds can be *answered* there (§5.4), because a verified message from the room's sole human member in their own agent thread satisfies Nate's "the human answers in their own agent thread" verbatim, after which "the agent records AGENT HUMAN ANSWERED" — also verbatim.
- Skill install approvals, digests, and status Q&A live there too.
- What does NOT move to Talk: local actions (installs, credential grants, running commands) — those still require the person's interactive Claude Code session; the room reply for such holds is a deep-link nudge, not the answer.

### 2.3 People ↔ others' agents (Nate: three sanctioned modes)

(a) **Give it work via its human** — "Assign cross-agent work to the human who owns the target agent, not to yourself", quadruple-keyed eligibility, cold-readability bar, presence check against the ledger first. (b) **Answer/observe on the issue** — receipts are the audit trail; anyone may answer an AGENT BLOCKED on the issue; nobody but the owner answers HOLDs. (c) **Read a memory slice** via scoped shared-MCP.

**What this design does with it:** cross-delegation keeps the human owner as the gate (S4): a request to another person's agent always lands as a *proposal in the owner's agent room*; only the owner releases it into their agent's queue. The presence check becomes automatic (the bridge reads the ledger and warns the requester if the target agent is stale) — mechanizing what Nate leaves as a manual rule. Memory-slice sharing is out of scope for v1 (team brain covers the need at 4-person scale).

### 2.4 Agents ↔ agents (Nate: never directly)

Four indirect mechanisms: the **claim lock** (status move + AGENT CLAIMED + re-read); **delegation + AGENT FOLLOW-UP** (an agent may create a correctly-addressed issue for another human's agent; checking delegated issues is a mandatory runner step before new work); **visible delegation** within one human (named tmux, orchestrator runs the verification gates); **continuity via memory** (compact work logs in the brain, never transcripts).

**What this design does with it:** untouched. Agents still never message each other — not even via Talk. The bots post only in their owner's room, the ops room, and on Deck cards. Agent-created cross-delegation (runner routes a card to another human's agent) follows BYGGPLAN §3.7 verbatim, plus the same owner-gate: the delegated card lands in Inbox assigned to the target human with the `delegated` label, and the *owner* (not the delegating agent) releases it (§7 S4 variant B).

### 2.5 Nate's GAPS — which ones this angle answers, and how

| Nate GAP | Answered here by |
|---|---|
| GAP 1 — nothing pushes to humans | The entire Talk layer: event-driven pings + morning digest + on-demand `status` (§5, §6 S5). NC bell notifications remain as the degraded path. |
| GAP 2 — who reviews Agent Review | Explicit rule: **reviewer = requester; owner if self-originated**; the card's `## Output & handoff` names the reviewer; the review ping goes to exactly that person (§6 S3). Reject = comment-with-reason + re-queue (defined rework loop, no new token). |
| GAP 3 — who answers AGENT BLOCKED | Default answerer = requester (their room gets the question thread); the owner's room gets an FYI. Answer still lands ON the card — public and auditable, per Nate. Conflicting answers: last-one-on-the-card-before-resume wins; the runner quotes which answer it used in AGENT UNBLOCKED. |
| GAP 6 — follow-up has no consumer | AGENT FOLLOW-UP events are folded into the delegating human's digest ("your outbound asks changed state"), giving the receipt a defined reader. |
| GAP 7 — no SLAs/escalation | Digest aging: any card sitting in Agent Needs Input or Agent Review > 24 h is repeated in the responsible human's digest with age tag; > 72 h it also appears in Agent Ops. No auto-escalation beyond visibility — silence is still not consent. |
| GAP 12 — human fabric | Talk is the fabric; task threads double as human conversation. |

GAPs 4, 5, 8, 9, 10, 11 (standing-context governance, admin roles, team brain workflow, cross-owner maintenance, offboarding, scheduling fairness) are consciously NOT solved by this angle beyond what BYGGPLAN already does — see §10.

---

## 3. The surfaces — who looks at what

| Surface | Who | Frequency | Content |
|---|---|---|---|
| **Personal agent room** (Talk; ex-capture room: `Reb`, `Atlas`, `Ada`, `Marvin`) | Its one human + the bot | Many times daily; THE surface | Capture (unchanged), `!queue`, takeover confirmations, blocker/hold threads, review approvals, digests, `status` |
| **Agent Ops room** (Talk) | All four + bot | Daily glance | Alarms (BYGGPLAN §5.2), near-miss notices, weekly Monday digest, >72 h aging items, standing-version lag nags |
| **Human's own Deck board** | Its human | As today — their normal work | Their cards, now with one extra gesture (assign bot) and honest `agent:*` labels + one mirror comment per handed-over card |
| **`Agent Engine` Deck board** | Agents (bots) always; humans rarely | Humans: onboarding, debugging, deep audit | Verbatim Nate protocol: 7 stacks, receipts, ledger, standing cards. Never required for routine human decisions |
| **NC notifications (bell/mobile)** | Everyone | Passive | Redundant copies of every Talk ping (agent_engine already sends them, BYGGPLAN M4) — the degraded path when Talk is unavailable |
| **Interactive Claude Code session** | Each human | For real work + holds needing local action | Unchanged from BYGGPLAN §5.1; session-start preflight lists open holds |

---

## 4. The intake mechanic: "assign the agent → the card is taken over to the Agent Engine board"

### 4.1 The human gesture (on the human's own board)

Primary gesture: **assign the owner's bot user to the card** (`bot-reb` on Rebecca's board). Native Deck UI, two taps, works on mobile, semantically exact ("I give this to Reb"). Enrollment-optional secondary gestures, for teams that prefer them: apply the provisioned label **`→ agent`**, or drag to a provisioned stack **`Till agenten`**. All three converge on the same pipeline; the assign gesture is the one we teach.

### 4.2 Event choice and latency

- **Primary: in-process PHP event listeners in `agent_engine`.** `agent_engine` is an NC app on the same instance as Deck (BYGGPLAN §1.2 already has it listening to Deck events for the runner fan-out). It subscribes to `OCA\Deck\Event\*` card events for **enrolled boards** (registry table, §4.7). In-process dispatch means effectively **zero added latency** — no webhook_listeners, no 5-minute background-job lag, no unsigned outgoing webhooks. This is the "custom NC app escape hatch" the capabilities digest names, and we already own the app.
- **Verify at M4b** (new verification item): which concrete event class fires on `assignUser` / `assignLabel` / stack move on the deployed Deck version (the digest marks per-operation firing *uncertain* even for webhooks; in-process classes must be enumerated from the installed Deck source).
- **Fallback (pre-designed, also the reconciliation net): a 60-second `agent_engine` background job** that ETag-polls enrolled boards (`GET /boards/{b}/stacks`, 304s are cheap) and diffs bot-assignments against the `sync_links` table. This job runs *always*, even when events work — it is the missed-event safety net (§8.1). Worst-case gesture→pipeline latency is therefore 60 s, typical is < 2 s.

### 4.3 The takeover pipeline (deterministic, in `agent_engine`)

On detecting the gesture on an enrolled board:

1. **Idempotency check:** `sync_links` unique index on `(origin_board_id, origin_card_id, active=true)` — a second gesture on the same card is a no-op (§8.4).
2. **PII firewall:** the origin card's title + description + checklist are run through the §2.3 BYGGPLAN regex firewall *before anything is copied*. Hit ⇒ no engine card; Talk reply in the owner's room: *"Jag kan inte ta 'X' — kortet innehåller mönster som inte får in i agent-substratet (PII/secrets). Rensa kortet eller behåll det själv."* Origin card gets label `agent:refused`. (Human boards are inside the humans' authorization boundary; the engine board + brains are outside it — the invariant from the PII principle.)
3. **Create the engine card** on `Agent Engine`, stack **`Inbox`**, label `needs-enrichment`, assignee = the owner human, title `[inbox][<agentcode>] <origin title, truncated>`. Description gets the 8-section template **mechanically pre-filled**: `## Requester` = the gesturing human (+ "via takeover from <board>/<card link>"); `## Desired outcome` = origin title; `## Context` = origin description verbatim; `## Sources` = origin card link + origin attachments as links; `## Do`/`## Acceptance criteria` = origin checklist items if any, else `TBD`; `## Output & handoff` = `Reviewer: <requester>` + `TBD`; `## Boundaries` = the standard boundary block. **This card is not claimable** — wrong stack, no `agent-instructions` label. The BYGGPLAN Inbox invariant ("no path creates a claimable card without the full contract") is preserved and generalized.
4. **Mirror the origin card:** apply label `agent:queued`; post the **AGENT MIRROR comment** (§4.5).
5. **Record** `sync_links` row: `(origin_board, origin_stack, origin_card, engine_card, owner, agent_code, state='enriching', created_at)`.
6. **Trigger the enrich-run** (§4.4).

### 4.4 The enrich-run (the conversational card-enricher)

BYGGPLAN's `card-enricher` skill assumed the owner's *next interactive session* fills the template. Conversation-first moves that to a **headless enrich-run**, so the human's total effort is one Talk reply:

- The runner gets a second, lighter prompt file `prompts/enrich-run.md` (same container, same per-agent slot, same flock, `--max-turns 10`). Triggered by `agent_engine` push when an Inbox card with the agent's code appears. It: reads the engine card + the origin card (via the bot's own Deck access), recalls the agent's brain + team brain on the topic, **completes the 8-section template in place** (fills Do / Acceptance criteria / Output & handoff; drafts, never invents requirements it can't ground — ungroundable fields stay as explicit questions), rewrites the title to full grammar `[agent instructions][<agentcode>][task] <outcome>`, and asks `agent_engine` to post the **intake message** in the owner's Talk room:

  > **Reb:** Du gav mig *"Uppdatera kunddokumentationen"*. Så här tänker jag göra: → *mål:* dokumentationen för kundportalen uppdaterad till v2.4-flödet · *klart när:* alla skärmbilder stämmer + ändringslogg skriven · *resultat läggs:* i dokument-mappen, länk här. **En fråga:** gäller det även FAQ-sidan? — Svara i den här tråden: **"kör"**, svara på frågan, eller **"ta tillbaka"**.

- **Protocol safety:** the enrich-run **never claims, never executes task work, posts no receipt tokens**, touches exactly one Inbox card, and stops. It is the card-enricher skill executed headless — a justified ITSL extension recorded in the fidelity table (§7). One-task-per-run for *queue* runs is untouched.
- **Degraded mode:** if the enrich-run fails or the runner is down, `agent_engine` posts a deterministic intake message built from the mechanical template ("Jag har tagit kortet men behöver: vad räknas som klart? Svara här.") — intake never silently stalls (§8.6).

### 4.5 Queue release and the mirror contract

**Release:** the owner replies **"kör"** (or answers the question(s), then "kör") in the intake thread. `agent_engine`: writes the answers into the template sections (quoted, attributed `— rebecca via Talk <ts>`), applies `agent-instructions`, removes `needs-enrichment`, moves the engine card to **`Agent Todo`**, updates `sync_links.state='queued'`, updates origin label to `agent:queued`. The normal push fan-out fires; the runner claims within 60 s. From here on, the card is a 100 % standard Nate card.

**Two-way sync contract (the complete table):**

| Direction | Change | Sync action |
|---|---|---|
| engine→origin | Receipt/stack change (CLAIMED, BLOCKED, HOLD, RESUMED, DONE, FAILED, Review) | Update origin **status label** (`agent:queued` → `agent:working` → `agent:needs-you` / `agent:review` → `agent:done` / `agent:failed`) + **upsert the AGENT MIRROR comment in place** (bot-authored, so author-only edit works; ≤ 1000 chars): current status in Swedish, one-line latest receipt summary, engine card link, output link when done. Individual receipts are **not** copied to the origin card. |
| engine→origin | Terminal DONE (approved or no-review) | Label `agent:done`; final mirror comment with output link; **optionally** move origin card to the board's configured "done stack" (enrollment setting, §4.7 — we never guess the semantics of someone's board); Deck `done` flag set **only** on the engine card, never on the origin card. |
| origin→engine | Human comment on origin card | Forwarded to the engine card as a bot comment: `FROM ORIGIN (rebecca <ts>): "<quoted>"`. The runner re-reads comments after claim and on resume, so forwarded answers reach it. |
| origin→engine | Description/checklist edited **before "kör"** | Enrich state refreshes: template re-derived, intake thread gets an updated draft. |
| origin→engine | Description/checklist edited **after claim** (mid-flight) | Engine card gets `FROM ORIGIN: description edited (diff attached)` comment + the owner's room gets: *"Du ändrade kortet medan Reb jobbar — gäller ändringen det pågående uppdraget (svara 'uppdatera') eller efteråt (svara 'senare')?"* 'uppdatera' ⇒ comment marked as authoritative addendum; the runner treats it as new input on resume/next run. The engine card template stays canonical — origin edits are advisory until the human explicitly promotes them (§8.3). |
| origin→engine | Duedate set/changed on origin | Copied to engine card duedate (feeds native Deck "upcoming" surfaces). |
| origin→engine | Origin card deleted or moved to the board's done stack by the human | Auto-reclaim offer in Talk (§6 S7): *"Du gjorde klart/tog bort kortet själv — ska jag släppa uppdraget?"* |
| never | Engine receipts, ledger content, standing cards, brain content | Never mirrored to human boards. |
| never | Origin card title | The engine title is grammar-fixed at release; origin renames noted as a FROM ORIGIN comment only. |

### 4.6 Loop prevention (by construction, not by heuristics)

1. **Actor filter:** every `agent_engine` sync listener drops events whose actor is in the `agents-bots` group — the bridge never reacts to its own writes.
2. **Typed one-way mappings:** there is no generic "copy changes both ways"; only the enumerated field→action rows above exist. A mirrored label change on the origin card maps to *no* engine action; a forwarded comment on the engine card maps to *no* origin action. No cycle is expressible.
3. **In-place upsert:** the mirror comment and the intake state live in *one* bot-authored comment / one `sync_links` row — repeated syncs converge instead of accumulating.
4. **Idempotency keys:** `sync_links` unique active-link index; forwarded comments carry the origin comment id in a marker suffix and are deduped on it.

### 4.7 Provisioning (board enrollment)

Enrollment of a human board = one idempotent `occ agent_engine:enroll-board <boardId> --owner <uid>` (or a small admin UI later):
1. **ACL:** add the owner's bot user (`bot-reb` on Rebecca's board) with edit permission (needed for labels + comments + reading attachments). *Only the owner's bot* — minimal authority; cross-delegation flows via Talk + the owner (§6 S4), so other bots never need membership on personal boards. Un-enroll = remove ACL + deactivate registry row (labels remain, inert).
2. **Labels:** create board-scoped labels `→ agent`, `agent:queued`, `agent:working`, `agent:needs-you`, `agent:review`, `agent:done`, `agent:failed`, `agent:refused` (resolve-or-create by exact title — the DeckClient pattern ports 1:1; a rename on one board breaks matching on that board only, which the reconciliation job flags).
3. **Optional stack:** `Till agenten` if the team wants the drag gesture.
4. **Registry row** in `engine_meta`: `enrolled_boards(board_id, owner_uid, agent_code, done_stack_id NULL, gestures, enrolled_at)` — the one enrollment question a human answers: *"which column means 'done' on your board, if you want finished cards moved there?"*
5. **Bot-user provisioning delta vs BYGGPLAN:** bots remain members of `agents-bots` only; enrollment ACLs are per-board and explicit. No case-folder access, ever (PII posture unchanged).

---

## 5. The Talk grammar — how conversation maps to protocol

### 5.1 Threads are the binding

Every card-related bot message opens (or continues) a **thread**; `agent_engine` stores `talk_message_id → engine_card_id` in `engine_meta.talk_threads`. A human reply *in that thread* needs no command syntax at all — the thread IS the card reference. (M-verify: the Talk bot webhook payload carries reply-to metadata; the capabilities digest confirms Activity Streams object/target structure — confirm the replied-to message id is present on the deployed Talk version. Fallback: every bot message ends with the short id, e.g. `(AE-231)`, and the command grammar below always works un-threaded.)

### 5.2 The command grammar (fallback + power use; total surface a human might ever type)

| Message (in own agent room) | Effect |
|---|---|
| *(plain text)* | Capture to own brain (unchanged BYGGPLAN §4.2) |
| `!queue <text>` | Inbox card for own agent (unchanged §4.3) + now also triggers the enrich-run |
| `!queue ada: <text>` | Cross-delegation proposal to Sandra (S4) |
| `kör` / `kör AE-n` | Release enriched card to Agent Todo |
| *(any text in a question thread)* | Answer: forwarded to the card (blocked) or recorded as hold answer (§5.4) |
| `godkänn AE-n` / `underkänn AE-n: <skäl>` | Review verdict (S3) |
| `ta tillbaka AE-n` | Reclaim (S7) |
| `status` | On-demand digest (S5) |
| `paus` / `återuppta` | Sets own ledger `Automation state: paused` / `installed` (vacation mode, BYGGPLAN Ö10) |

Nothing else. Eight verbs, all Swedish, all phone-typeable. Ambiguity handling: the bot asks exactly once, then falls back to posting the card link ("enklast att titta här: <link>").

### 5.3 Attribution rule (applies to every bridged write)

Talk messages in a personal agent room are authenticated (HMAC-verified webhook + provisioned membership: exactly one human in the room). When the bridge writes the human's words onto a Deck card, the comment is authored by the bot account but always carries the attribution line `— <uid> via Talk <ISO-ts> (msg <id>)`. This is a *conscious, documented trade* (same class as BYGGPLAN's accepted team-brain MCP attribution risk): protocol receipts stay bot-authored and structurally attributable; human words are attributable via the verified Talk trail.

### 5.4 The two pause channels in Talk (fidelity-critical)

- **AGENT BLOCKED** (answer belongs ON the card, publicly): the bot posts the agent's ONE specific question as a thread in the **requester's** room (owner's room gets a one-line FYI if different). The reply is forwarded to the engine card as a `FROM TALK`-attributed comment — so the answer *does* appear on the card, satisfying Nate's "resume only after the missing answer appears on the same issue" while the human never opens Deck. The comment event push-triggers the runner: `AGENT UNBLOCKED` → `AGENT RESUMED`.
- **AGENT HUMAN HOLD** (answer belongs in the human's own agent thread/app): the personal room *is* that thread (§2.2). Two subtypes, distinguished by the runner in the hold text:
  - **Grant-holds** ("May I install X? / May I use account Y for Z?") — answerable in the room. On a verified yes/no from the owner, `agent_engine` posts `AGENT HUMAN ANSWERED` on the card with the attribution line, clears `human-hold`, and the runner resumes. Nate-verbatim: the human answered in their own agent thread; the agent records AGENT HUMAN ANSWERED.
  - **Action-holds** (something must be *done* locally: install, credential, permission dialog) — the room message says so and deep-links: *"Det här kräver din egen session: öppna Claude Code, preflighten visar holden."* The BYGGPLAN §3.5 flow then runs unchanged (the interactive session performs the action and posts `AGENT HUMAN ANSWERED` as the human).

### 5.5 Digest formats (S5/S6 payloads)

**Morning digest** (per person, 08:00, own room; suppressed if all-empty):
```
God morgon! ⛅
VÄNTAR PÅ DIG (2)
 • Granska: "Release notes v1.3" — Marvin blev klar 16:40 igår → svara 'godkänn AE-217' / 'underkänn AE-217: …'   [väntat 16 h]
 • Fråga: "Uppdatera kunddok" — Reb undrar om FAQ-sidan ingår → svara i tråden ovan   [väntat 2 h]
KLART SEDAN IGÅR (3)
 • Kunddok-skärmbilderna uppdaterade → <länk>  • … 
PÅGÅR / I KÖ (2): "X" (working, 40 min), "Y" (kö, plats 1)
DINA UTGÅENDE (1): AE-240 hos Ada — nu Working (AGENT FOLLOW-UP)
```
Aging: items > 24 h get the `[väntat …]` tag bolded; > 72 h they also post to Agent Ops. **Monday team digest** (Agent Ops, automated from `engine_meta.runs`): per-agent completed/blocked/failed counts, week's spend per key, stale ledger heartbeats, Review-queue age histogram — the BYGGPLAN Monday-sync SQL, executed by the bot instead of a human.

---

## 6. The seven scenarios, end-to-end

Notation: 👆 = human act, 🤖 = system/agent act, 🔔 = notification. "Origin" = the human board card; "AE-n" = the engine card. Every receipt named is byte-identical to BYGGPLAN §3.3.

### S1 — Rebecca hands "Uppdatera kunddokumentationen" to Reb

1. 👆 Rebecca, on her own board, opens the card → assignees → picks **bot-reb**. (Total: 2 taps. She is done unless she wants to answer a question.)
2. 🤖 ≤ 2 s: in-process event → takeover pipeline: firewall pass → AE-231 created in `Inbox` (`needs-enrichment`, mechanical template) → origin: label `agent:queued` + AGENT MIRROR comment ("Reb har tagit uppdraget · förbereder · <AE-231-länk>") → `sync_links` row.
3. 🤖 ≤ 30 s: enrich-run completes the template, sets title `[agent instructions][reb-claude][task] Uppdatera kunddokumentationen till v2.4`, and 🔔 the intake message lands in Rebecca's agent room (§4.4 example) + NC bell.
4. 👆 Rebecca (phone): *"gäller inte FAQ. kör"*.
5. 🤖 Answer written into `## Do`/`## Boundaries` (attributed), `agent-instructions` applied, AE-231 → `Agent Todo`; push fan-out → runner claims: `POST /claim` 200 → **AE-231 → `Agent Working` + `AGENT CLAIMED`** (atomic); ledger `claimed AE-231`. Origin: label → `agent:working`, mirror comment updated ("Arbetar · claimad 09:14"). Elapsed since "kör": < 60 s.
6. 🤖 Work happens (recall → do → writeback). **`AGENT DONE`**; docs-update requires no judgment call per the card ⇒ AE-231 → `Agent Done`, Deck done-flag set, ledger `completed AE-231`, `engine_meta.runs` row written.
7. 🤖 Origin: label → `agent:done`; mirror comment final ("Klart 10:02 · ändringslogg + skärmbilder: <länk> · kvitton: <AE-231>"); origin card moved to Rebecca's configured "Klart" stack (she chose one at enrollment). 🔔 One line in her room: *"Klart: kunddokumentationen uppdaterad → <länk>."*
8. **How she sees status at any time:** glance at her own board (label + mirror comment), or type `status`, or just wait — every state that needs her arrives as a push. She never opened the engine board.

### S2 — Reb hits AGENT BLOCKED

1. 🤖 Mid-run Reb lacks a work-content answer (e.g. "which product version's screenshots?"). Runner posts **`AGENT BLOCKED`** + the ONE question on AE-231; card → `Agent Needs Input` + label `blocked`; ledger `blocked AE-231`.
2. 🤖 ≤ 2 s: `agent_engine` → origin label `agent:needs-you`, mirror updated; 🔔 Talk thread in **Rebecca's** room (she is requester and owner here): *"Reb är blockerad på 'Uppdatera kunddok': Vilken produktversion ska skärmbilderna visa — 2.4.0 eller 2.4.1? Svara här."* + NC bell.
3. 👆 Rebecca (phone, 10 s): *"2.4.1"* in the thread.
4. 🤖 Bridge posts on AE-231: `FROM TALK (rebecca 09:31): "2.4.1"`. Comment event → push → runner (flock + 60 s debounce): sees the answer on the card → **`AGENT UNBLOCKED`** + **`AGENT RESUMED`** → card → `Agent Working`; origin label back to `agent:working`; finishes → S1 steps 6–7. Answer-to-resume: ≤ 90 s.
5. Audit: the question AND the answer are both on the engine card forever — Nate's "public work questions" invariant intact.
6. If Rebecca instead answers directly on the engine card in Deck (she may!): identical outcome; the Talk thread gets a closing note ("Besvarat på kortet — återupptar").

### S3 — Agent Review: Mattias reviews Marvin's work

1. 🤖 Marvin finishes a card whose work needs judgment (per card `## Output & handoff`, reviewer: mattias — he was the requester). **`AGENT DONE`** + card → **`Agent Review`** (not Agent Done); ledger `completed AE-217`. Origin (if the card came via takeover from Mattias's board): label `agent:review`, mirror updated.
2. 🔔 Talk in Mattias's room: *"Marvin är klar med 'Release notes v1.3' och vill ha din granskning. Resultat: <fil-länk>. Kvitton: <AE-217>. Svara 'godkänn AE-217' eller 'underkänn AE-217: <skäl>'."*
3. 👆 Mattias opens the **output artifact** (a Nextcloud file / PR — he reviews the work where the work lives, not on the board). Two outcomes:
   - **Approve:** 👆 *"godkänn AE-217"* → 🤖 `agent_engine` moves AE-217 → `Agent Done`, sets done-flag, posts card comment `Approved — mattias via Talk 11:02 (msg 8841)`; origin `agent:done` + final mirror; requester notified if different. No agent run needed — approval is a human act recorded by the system, not a new token.
   - **Reject:** 👆 *"underkänn AE-217: fel version i rubriken, och ta med hubs_start-fixarna"* → 🤖 comment `REWORK — mattias via Talk 11:02: "<skäl>"` posted on AE-217; card → **`Agent Todo`** (re-queue, same card, template intact); push → Marvin's runner claims again (`AGENT CLAIMED`), reads comments after claim (runner step 12 re-read includes latest comments), addresses the reasons, → `AGENT DONE` → `Agent Review` again → step 2 repeats. This is the defined rework loop for Nate's GAP 2 — no new receipt token, the loop is visible as comments + stack history.
4. **Silence is not consent:** an unreviewed card just ages in `Agent Review` and reappears in Mattias's digest daily (>72 h: Agent Ops). Nothing auto-proceeds.

### S4 — Cross-delegation: Fredrik wants Ada (Sandra's agent) to take a job

**Variant A — Fredrik initiates (human-initiated):**
1. 👆 Fredrik, in **his** agent room: *"!queue ada: Gå igenom pekare-testfallen i hubs_arende och föreslå saknade kantfall"*.
2. 🤖 The bridge consults the **routing map** (standing card, parsed): is this within "Routa till Sandra för: …"? If mismatch → warning *in Fredrik's thread* (*"Det här ser ut som Mattias område enligt routingkartan — skicka ändå? (ja/nej)"*) — the near-miss philosophy applied *before* the card exists. Then the **presence check**: reads Ada's `AGENT STATUS` ledger comment; if heartbeat stale/paused → *"Ada har inte hörts av på 26 h (paused). Sandra får förslaget ändå, men räkna inte med snabb pickup."* (Nate's "say that before relying on the handoff", mechanized.)
3. 🤖 Card created: `Inbox`, `needs-enrichment`, title `[inbox][ada-claude] Gå igenom pekare-testfallen…`, **assignee = sandra** (Nate: "assign cross-agent work to the human who owns the target agent"), `## Requester` = Fredrik + his Talk handle. Ada's enrich-run drafts the full template (cold-readability is the enricher's job — it must produce a card the agent can read cold, or leave explicit questions).
4. 🔔 **Sandra's** room: *"Fredrik ber Ada: 'Gå igenom pekare-testfallen…'. Utkast klart: <AE-240>. Svara 'kör' för att släppa in det i Adas kö, eller ställ frågor till Fredrik i tråden."* — **the owner is the gate**; Fredrik cannot inject work into Ada's queue past Sandra.
5. 👆 Sandra: *"kör"* → 🤖 → `Agent Todo` → Ada claims (`AGENT CLAIMED`) within 60 s.
6. 🤖 Fredrik's room gets the lifecycle he cares about: queued confirmation, and completion (*"Ada klar med pekare-genomgången → <länk>"*). Review, if required, goes to **Fredrik** (requester = default reviewer, §2.5 GAP 2). Mid-flight AGENT BLOCKED questions route to **Fredrik's** room (requester default) — e.g. *"Ada undrar: ska prestandafallen ingå?"*.
7. **Near-miss protection** (unchanged BYGGPLAN §3.2) still guards the engine board: if anything about the card is malformed when released (wrong assignee, typo'd code, missing label), the owner gets the "this card will never be picked up: <reason>" notice — now via her agent room + NC bell.

**Variant B — an agent initiates (agent-routed delegation, Nate §4b):** Atlas, during a run, determines a subtask belongs to Ada per the routing map. It creates the same Inbox card (assignee sandra, bracket ada-claude, label `delegated`), posts nothing else, and continues its own single task. Sandra's room gets the same release prompt (step 4). Atlas's subsequent runs check delegated cards (runner step 8) and leave **`AGENT FOLLOW-UP`** on state change; the follow-up is surfaced in *Fredrik's* digest ("DINA UTGÅENDE") — the delegating agent's owner is the follow-up's consumer (GAP 6 answered).

### S5 — Daily overview for a non-technical person: "what did my agent do / what waits on ME?"

1. 🔔 08:00: the **morning digest** (§5.5) in her agent room. Everything actionable is a reply, right there: 'godkänn AE-217', answer a thread, 'kör'. Zero navigation; readable in 20 s on a phone.
2. 👆 On demand, any time: *"status"* → 🤖 the same digest, computed fresh (source: `engine_meta.runs` + engine-board state + `sync_links` — one SQL + one board read).
3. 👆 Passive alternative (no Talk at all): her **own Deck board** tells the honest story via `agent:*` labels + mirror comments; the native NC dashboard "upcoming cards" widget shows duedated agent cards. (Surfaces doc Option A — free.)
4. What she never needs: the engine board, the ledger card, receipt tokens, any English protocol vocabulary. The digest translates `blocked/holding/completed` into "väntar på dig / klart".

### S6 — Team rituals

- **Monday sync (10 min, all four):** the bot has already posted the **Monday team digest** to Agent Ops at 08:30 (runs/agent/result, spend per key vs cap, stale heartbeats, Review-queue aging, near-miss orphans of the week). The humans discuss exceptions only; nobody runs SQL by hand (BYGGPLAN §8 ritual, automated but not skipped — the *conversation* is the ritual).
- **Friday 10 min/person:** the bot posts a personal Friday checklist to each room: open Review cards (with age), Inbox cards never released ("3 kort har väntat > 5 dagar — kör eller släng?"), M12+: pending brain memories with a Brain Review deep-link. The human replies inline or clicks through.
- **Who looks at the ledger, when:** *agents every run; humans almost never.* The bot reads it for them: presence warnings on delegation (S4), stale-heartbeat lines in digests, `Automation state: paused` surfaced in vacation mode. Humans open the ledger card itself only during onboarding smoke tests and incident debugging — and it is always there, complete and verbatim, when they do.
- **Standing updates:** version bumps to standing cards are announced in Agent Ops (who bumped, what changed); `AGENT APPLIED` receipts accumulate on the standing card as usual; the bot nags an owner in their room if their runtime lags a mandatory version > 48 h ("Reb kör routing map v1, aktuell är v2 — kör sync"). (Governance of who MAY bump — Nate GAP 4 — stays out of scope, §10.)
- **Maintenance loop:** unchanged BYGGPLAN §8.5 (trigger-driven, owner-run). The digest is a trigger *feeder*: repeated failure/rework lines in a person's digest are exactly Nate's "rising human cost" signal.

### S7 — Human finishes the task herself mid-flight (and: may an agent take things from a human board?)

**A. Take-back / cancel:**
1. 👆 Rebecca, any time: *"ta tillbaka AE-231"* in her room (or in the card's thread just "ta tillbaka"), or simply unassigns bot-reb on the origin card, or ticks the origin card done / moves it to her done stack — the reconciler treats all of these as a reclaim signal (the two Deck gestures get a confirm in Talk first: *"Ska jag släppa uppdraget? (ja/nej)"* — accidental unassign must not kill work silently).
2. 🤖 By engine-card state:
   - `Inbox`/`Agent Todo` (not yet claimed): engine card archived with comment `Reclaimed by rebecca via Talk <ts>`; origin labels cleared; mirror comment final ("Tillbaka hos dig — inget påbörjat"); `sync_links.state='reclaimed'`. Instant.
   - `Agent Working` (claimed, run possibly in flight): `agent_engine` sets a **reclaim flag** on the sync link + posts `RECLAIM REQUESTED — rebecca via Talk <ts>` on AE-231. Runs are short (one card, `--max-turns 40`); we do not kill a live process. The runner wrapper checks the flag **after** the run: if set, whatever receipt the run produced stands (receipts are history, never deleted), and `agent_engine` then moves the card to archive with `Reclaimed — output so far preserved in receipts/attachments`, clears origin labels, final mirror comment: *"Tillbaka hos dig. Reb hann: <last receipt one-liner>; delresultat: <länk om något>."* If the run completes with `AGENT DONE` before the flag is seen, the completion wins and Rebecca is told her reclaim arrived after the finish — she keeps the output.
   - `Agent Needs Input`/`Agent Review`: immediate archive + label cleanup (nothing is running).
3. 🤖 The agent's partial write-back to its brain (runner step 18) stands — reclaimed work still leaves memory, which is correct and harmless.
4. Ledger: next run records normally; no special token is invented — reclaim is a *human* act recorded as attributed comments + archive, exactly like review approval.

**B. Reverse: the agent proposes to take over something it sees on a human board — får den?**
**It may propose; it may never take.** Rules (enforced in the digest generator, not left to prompt discipline):
- Suggestions appear in exactly one place: the owner's **morning digest**, max ONE per day: *"Jag ser 'Skriv changelog för v1.3' på din tavla — det liknar AE-190 som jag gjorde förra veckan. Vill du ge den till mig? (svara 'ta den')"*.
- Only cards on the owner's own enrolled boards, unassigned or assigned solely to the owner. Never other people's cards, never other boards, never mid-thread interruptions.
- 👆 "ta den" = the human trigger; only then does the standard takeover pipeline (§4.3) run. Silence means no, forever (no repeat nag for the same card).
- Fidelity note: Nate has no analogue (agents act only on addressed engine issues); this is a strictly-additive, human-gated suggestion surface — the agent still never claims anything outside the engine board's `Agent Todo`.

---

## 7. Fidelity audit — nothing in Nate's protocol breaks

| Nate invariant | Status here |
|---|---|
| 6 statuses + Inbox, exact stack semantics | Untouched; takeover lands in Inbox like `!queue` does |
| Title grammar `[agent instructions][code][task]` | Untouched on the engine board; produced by enrich-run, never by humans |
| 16-token receipt vocabulary, byte-identical | Untouched; **zero new tokens** (approval/rework/reclaim are attributed human comments + stack moves, not agent receipts) |
| Atomic claim, one winner (200/409) | Untouched; only engine-board `Agent Todo` cards are claimable; no takeover path creates a claimable card without the full 8-section contract |
| One task per run; paused work before new work; stop after one | Untouched for queue runs. **Justified extension:** the *enrich-run* — a non-claiming, non-executing, receipt-free run type that fills exactly one Inbox card (the card-enricher skill moved from interactive to headless). Documented in Bilaga-A-style deviation list. |
| Cold-readability of routed cards | Strengthened: the enricher owns it mechanically; ungroundable fields become explicit human questions before release |
| Assignee = owner human; agent code in bracket 2 | Untouched; cross-delegation always assigns the owner, and adds an owner release gate (stricter than Nate, never looser) |
| BLOCKED answered on the card, publicly | Preserved: Talk answers are *written onto the card* before resume; Talk is transport, the card is the record |
| HUMAN HOLD answered in the human's own agent thread/app | Preserved and sharpened: the personal Talk room IS that thread (grant-holds); action-holds still route to the interactive session; `AGENT HUMAN ANSWERED` posted per spec |
| Ledger: one in-place AGENT STATUS comment per agent | Untouched; gains readers (the bot quotes it in presence checks and digests) |
| Routing map + presence check before handoff | Untouched; presence check automated at the moment of delegation |
| Standing preflight, AGENT APPLIED, approval-creates-subscription | Untouched |
| "Silence is not consent"; human-only judgment list | Untouched; Review never auto-proceeds; digests only *repeat*, never decide |
| PII firewall before anything leaves the boundary | Extended to the takeover path: origin content is firewalled before copying (§4.3.2) |

---

## 8. Failure modes and their handling

1. **Missed intake event** (listener gap, app upgrade window, event class not firing on this Deck version): the always-on 60 s reconciliation job (§4.2) diffs bot-assignments on enrolled boards vs `sync_links` and back-fills; worst case the intake message says *"Jag såg kortet först nu."* Nothing is ever lost while the origin card still carries the gesture (assignment/label are durable state, unlike events).
2. **Talk down / bot rate-limited (429):** all pings are duplicated as NC notifications (bell/mobile) already; outbound Talk goes through a retry queue with backoff. Humans can always act natively in Deck (answer on the card, move Review→Done manually) — the protocol never depends on Talk. Digest jobs skip-and-log rather than pile up.
3. **Human edits origin card mid-flight:** typed contract row (§4.5): advisory `FROM ORIGIN` comment + an explicit promote question in Talk. The engine template stays canonical; no silent scope change reaches the runner.
4. **Double gesture / re-assign after completion:** unique active `sync_links` row makes repeat gestures no-ops; after a link closes (done/reclaimed), a fresh gesture creates a NEW engine card — deliberate ("do it again" is a new task), and the mirror comment history on the origin card keeps the lineage readable.
5. **Label renamed/deleted on a human board:** reconciliation job detects missing `agent:*` labels on an enrolled board, re-creates by title, and posts a one-line note to Agent Ops (board-scoped labels are the known fragility; resolve-or-create is the known cure).
6. **Enrich-run fails or runner down at intake:** deterministic fallback intake message from the mechanical template (§4.4); the card waits safely in Inbox (non-claimable); Friday checklist catches Inbox cards that were never released.
7. **Reclaim races a finishing run:** completion beats reclaim if `AGENT DONE` lands first (§6 S7.A.2); the human is told either way; receipts are never rewritten.
8. **Ambiguous Talk reply:** one clarify question, then a card deep-link. The bot never guesses on verbs that mutate state (`kör`, `godkänn`, `ta tillbaka` require exact-match or thread context).
9. **Cross-answer conflict on a blocked card** (two teammates answer differently): the runner quotes which answer it used inside `AGENT UNBLOCKED`; the requester's thread shows the quote; a human who disagrees reclaims or re-queues. (GAP 3 handled by visibility, not adjudication.)
10. **Prompt injection via origin cards/Talk:** origin card text and Talk replies are untrusted input exactly like `!queue` text (BYGGPLAN §7); authority still flows only from standing cards + the claimed card's own Do/Boundaries; the hostile-fixture smoke test gains a takeover variant (hostile origin card → enrich-run must surface, not obey).

---

## 9. Build deltas vs BYGGPLAN (what this angle adds)

| # | Delta | Size | Where it lands |
|---|---|---|---|
| 1 | `agent_engine`: enrolled-board registry, takeover pipeline, sync listeners + typed mirror contract, `sync_links`/`talk_threads` tables (in `engine_meta`), reconciliation job, reclaim flow, enroll `occ` command | ~1 week | M4b (after M4, before M7) |
| 2 | capture-bot → **agent-room bridge**: thread mapping, command grammar (8 verbs), attribution lines, review/release/reclaim write-through, digest scheduler (morning/Friday/Monday) | ~1 week | M4b/M7 |
| 3 | runner: `enrich-run` prompt + trigger path; wrapper reclaim-flag check; grant-hold vs action-hold phrasing rule in queue-run prompt | ~2 days | M5/M6 |
| 4 | Provisioning: per-board enrollment (ACL + labels + optional stack + done-stack question); no new accounts, no new keys beyond existing inventory | hours/board | M7 |
| 5 | New permanent smoke tests: takeover E2E (gesture→CLAIMED→DONE→mirror), firewalled origin card refused, blocked-via-Talk resume, grant-hold-via-Talk resume, reject/rework loop, reclaim in each state, double-gesture idempotency, reconciliation back-fill (listener disabled), hostile origin card | with each delta | M4b–M7 |
| 6 | New M-verifications: (a) which Deck event classes fire in-process on assignUser/assignLabel/reorder on the Hubs version; (b) Talk webhook reply-to metadata; (c) reaction events (optional v1.1 approve-by-👍) | ½ day | M4b |

Sequencing note: nothing here blocks M0–M7 as planned; delta 1–2 can land as M4b in parallel with M5/M6, and S1-style takeover becomes part of the M7 per-person onboarding demo ("watch your own card come back done").

---

## 10. What this angle deliberately does NOT build

- **No Vue dashboard, no widget, no custom GUI** (surfaces doc options C/D deferred): the thesis is that a good conversation + honest labels beat a new screen at 4-person scale. Revisit only if digest length or interaction depth proves the thesis wrong (measurable at the M11 gate).
- **No restructuring of human boards**: no forced stacks, no imposed conventions beyond 8 inert labels and one optional stack; the human's board stays theirs.
- **No receipt mirroring to origin cards** (one upserted mirror comment only) — receipt streams on human boards would be noise and a second truth.
- **No rich Talk UI**: no buttons/forms; plain text + threads (reaction-approve is a noted v1.1 option, not v1). No NLU beyond 8 exact verbs + thread context — misparse risk is a trust-killer.
- **No auto-approval, no timeout-consent, no auto-escalation actions**: aging creates *visibility* (digest, Agent Ops), never state changes.
- **No agent↔agent messaging, no agent posting in another human's room**, no agent claiming from human boards (suggest-only, S7.B).
- **No standing-context governance, roles/ACL model, offboarding flow, team-brain review workflow** (Nate GAPs 4/5/8/9/10): consciously left to a later hardening pass, per BYGGPLAN's "governance off the critical path" judge ruling.
- **No email digests, no external channels**: Talk + NC notifications only — one fabric.

---

## 11. Open questions to settle at M0/M4b

1. Confirm in-process event classes for assignUser/assignLabel/reorder on Hubs's Deck version (drives which gestures are event-driven vs reconciler-driven).
2. Talk reply-to metadata availability (drives threads-as-binding vs `AE-n` fallback).
3. Enrollment policy: which human boards are work-boards eligible for enrollment (PII posture question — boards touching case content are never enrolled)?
4. Digest times (08:00 personal / 08:30 Monday team?) and whether the evening digest exists at all.
5. Reviewer default (requester) — confirm with the team, since it changes who gets pinged on every Review.
6. Does "kör" require the owner only, or may the requester release a cross-delegated card if the owner pre-approves a standing rule? (v1: owner only.)
