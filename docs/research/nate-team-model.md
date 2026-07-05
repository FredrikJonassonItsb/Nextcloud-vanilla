# Nate B Jones's Team Interaction Model in the Open Stack — Deep Extraction

Sources: Open Engine guide (esp. §11 Team Path, §08 runner, §09 receipts, §13 troubleshooting), Open Stack Field Guide, Agent Maintenance Loop digest, Agent Operations + Context Engineering skill digests, runbooks digest, OB1 agent-memory + server-primitives digests, public-research synthesis, KARTLAGGNING.md §4.13/§5/§6. All quotes verbatim from those sources.

---

## 0. The model in one paragraph

Nate's team model is **not** a multi-agent swarm. It is: each human owns their agent runtimes; agents never talk directly to each other; ALL cross-boundary interaction (human→other-human's-agent, agent→agent) is mediated by **Linear issues with a strict title/label/status/assignee contract, a receipt vocabulary, a status ledger, and a routing map**. "A team engine is the same system with one extra rule: route work to the human who owns the target agent." The five-part loop underneath everything is **"memory, method, boundary, receipt, judgment"** — and in a team, judgment (approvals, review, answers) is deliberately pinned to a specific human via ownership. Nate: *"I've been running a working version to get content out, organize my life, move houses, and coordinate with my team."* His diagnosis of why the team layer exists at all: once agents work, *"the bottleneck moves to handoffs, state, receipts, and review."*

---

## 1. PEOPLE ↔ PEOPLE

Nate specifies surprisingly little direct human-to-human protocol; what exists is agreements-about-structure, made before automation:

1. **Naming decisions are a team meeting, made first.** "Most failed first runs come from mismatched team names, labels, statuses, issue titles, or agent codes." The team picks: the Linear team, the project (`Team Agent Engine`), the exact label `agent-instructions`, stable agent codes per runtime (`alex-codex`, `alex-claude`, `sam-codex`), and names the private setup issue + status ledger "before automation exists, so every prompt points at the same source of truth."
2. **The routing map is the human org-chart artifact** (a private Standing issue). Per human: Linear assignee, agent code(s), ownership area ("Route to Alex for: engineering, local repo work, QA" / "Route to Sam for: editorial review, social drafts"). Humans agree on and maintain this map.
3. **Onboarding is person-to-person, serialized:** "Onboard one teammate at a time: install context, create their AGENT STATUS comment, then run a tiny smoke test assigned to them."
4. **Human contact info travels inside every task:** the task template's first body field is `<requester>Who is asking and how to follow up.</requester>` — the escape hatch to human↔human conversation is embedded in the work item, but the medium (Slack, email, hallway) is unspecified.
5. **Approval among humans:** routing map rule (verbatim): "Human approval is required for publishing and customer-facing changes." Field guide: humans keep "accounts, auth, secrets, browser-only settings, billing, external publishing, destructive changes, customer-facing actions, and final judgment." External/destructive actions "require issue-level approval" — i.e., a human writes the grant into the issue, visible to the whole team.
6. **Agent-mediated human-to-human communication:** the Stakeholder Update Email skill formalizes the outbound half — updates "only when shipped, only what's true, only in their language," send requires explicit user confirmation. Runbooks 02/03/06 end with it ("closes the loop with whoever's waiting").

---

## 2. PEOPLE ↔ THEIR OWN AGENT

This is the richest channel. Two distinct surfaces per person:

### 2a. The private channel: "the human's own agent thread/app"
- Home of the **AGENT HUMAN HOLD** pause (see §5). Everything about *the runtime itself* is answered here: "local runtime permissions, skill install approval, automation setup, account authority, or private agent-thread context."
- **Optional-skill approval happens here, and creates a subscription:** "First install/adaptation of an optional standing skill requires human approval in this runtime's own agent thread/app. First approval subscribes this runtime to future same-scope updates for that optional skill. Expanded capability, new authority, new tool access, or a runtime change requires fresh approval." (Receipts: AGENT SKILL SUBSCRIBED / INSTALLED / UPDATED / DECLINED, left on the canonical skill issue.)
- Discovery is pull-based: "When the human asks what optional skills are available, read the optional standing skill directory and summarize relevant options" — never browse or install during routine runs.

### 2b. Configuration & boundaries (the contract the human writes)
- A **local private context file** per runtime (agent code, team/project, ledger ID, subscribed skills, allowed sources) + a **private Standing setup issue** holding "org charts, brand guides, customer context, secrets, account details, and private skill bodies."
- The ask-first boundary, verbatim from the runner: "Never publish, email, Slack-post, deploy, delete, change billing, change credentials, or make outward-facing changes unless the issue explicitly grants that approval."
- Field-guide skill adoption map, per skill: **"Manual only / Agent may use after asking / Agent may use automatically inside a specific workflow."**

### 2c. Task interaction with your own agent
- You file issues assigned to yourself with your agent's code in the second title bracket; the runner claims the oldest eligible one, **one per run**.
- You answer its AGENT BLOCKED questions on the issue, its AGENT HUMAN HOLD questions in the thread; you review Agent Review items; you run the smoke tests (hello-world, blocked-resume, human-hold, directory check) before trusting it.

### 2d. Maintenance (the human as agent-owner)
The Agent Maintenance Loop is explicitly a human ritual over the whole harness ("instructions + sources + memory + tools + permissions + model + human review + evals"). **Triggers, not calendar** — four families, any one is enough: **Upstream change, Scope creep, Rising human cost, Quiet failure.** Scope: "one agent and one signal" per pass. Division of labor is spelled out per step ("You do / The AI does"): the human names the trigger and the job sentence, picks the decision; the AI inventories the harness, restates the trigger as a checkable question, reads runs. Ends in exactly one written decision — **keep / change / pause / retire** — with a 7-field maintenance record "stored where the agent's config and docs live, not in a chat log." Key team-relevant surface: **Reach** — "can it touch more than its owner can review?" Fix: "narrow reach until every risky action passes a person."

### 2e. Memory governance (OB1 Agent Memory)
The recall/write-back contract: agent recalls scoped memories (with `use_policy`) before work → works → writes back **compact** memory (never transcripts; regex firewall 422-blocks secrets/code-dumps/transcripts) → lands `review_status='pending'` → **the human reviews** via the review queue (`/memories/review`) with actions confirm / edit / evidence_only / restrict_scope / mark_stale / merge / reject / dispute / supersede. The load-bearing invariant is a DB CHECK: `can_use_as_instruction = false OR provenance_status IN ('user_confirmed','imported')` — **"Agent-written memory starts as evidence, not instruction. Instruction-grade memory requires human confirmation or trusted import."** Confirm is the only path that promotes generated memory to instruction-grade. Scope rule with team bearing: **"Personal or channel memory never auto-promotes to team or workspace scope."**

---

## 3. PEOPLE ↔ OTHERS' AGENTS

You never command someone else's agent directly. Three sanctioned interaction modes:

### 3a. Give it work — via its human (the ONE extra team rule)
Verbatim: **"Assign cross-agent work to the human who owns the target agent, not to yourself."** And: **"Do not assign another person's agent task to yourself and expect their automation to see it."** The task template's assignee field: "The human/operator whose local agent should execute the ticket." Eligibility is quadruple-keyed: assignee = owner human, label `agent-instructions`, `[agent instructions]` title marker, target agent code in the second title bracket (`[agent instructions][sam-codex][task]`).

**Cold-readability requirement** (this is the handoff quality bar): "Write every routed task so the target agent can read it cold: requester, outcome, sources, acceptance criteria, output location, boundaries, and pause rule." Full task body schema: requester / desired_outcome / context / sources / do / acceptance_criteria / output_handoff / boundaries. The Goal Prompt Generator skill is the same idea as a capability: definition of done + repo constraints + verification gates + stop conditions, quality check "could a competent agent with zero context execute this and could I verify the result without re-deriving the plan?"

**Presence check before handoff:** "If the target agent is not online in the status ledger, say that before relying on the handoff." Troubleshooting for dead handoffs: "check the routing map, target heartbeat, label, title marker, and status."

### 3b. Answer it / observe it — on the issue
- Anyone reading the issue sees the receipts (AGENT CLAIMED/DONE/BLOCKED...) — the issue history is "the audit trail."
- An **AGENT BLOCKED** question from someone else's agent is answered *on the same Linear issue* (the guide does not restrict who may answer — typically the requester, since the agent must "ask one specific question"). You cannot answer its HUMAN HOLDs — those belong to its owner's private thread only.
- **Agent Review** status is the human-judgment inbox: "Completed work that still needs human judgment, quality assurance, or approval." (Who reviews — requester or owner — is unspecified; see GAPS.)

### 3c. Read (a slice of) its memory — the shared-MCP pattern
OB1's **Shared MCP Server primitive** is the mechanism for giving others "scoped access to parts of your brain": a **separate MCP server with scoped credentials, limited table access, controlled permissions** — never the main key. Three-layer security: (1) scoped credentials (separate role/key, independently revocable), (2) limited table access (RLS + GRANTs; sensitive tables invisible), (3) per-table read-only vs read-write (DELETE blocked entirely). Process: write an explicit **sharing decision map** ("Default to not sharing unless there's a clear reason"), scoped Postgres role, separate Edge Function + separate access key; the other person connects via URL only, "never see[s] your main server credentials"; **revocation = rotate the shared access key**. Operational doctrine: "audit shared scope regularly … start read-only and widen as trust builds … write a user guide for the other person." Boundary test must prove `thoughts: BLOCKED` and `DELETE: BLOCKED`. (Note: this is household/collaborator-scale point-to-point sharing — not a team-wide shared brain; see GAPS.)

Also: in the Code Review Memory recipe, a **maintainer** reviews an agent's written memories ("maintainer review queue"; "Maintainer corrections can supersede older lessons") — a human governing an agent's learning, potentially across ownership lines.

---

## 4. AGENTS ↔ AGENTS

Agents never message each other. Four indirect mechanisms:

### 4a. The claim lock (mutual exclusion)
"Move status to Agent Working before task work, leave AGENT CLAIMED, then re-read the issue. That status move is the visible lock." Two runtimes under one operator are further scoped by the agent-code bracket "so only the intended runtime claims each task." AGENT CLAIMED is "the claim lock that stops another runtime from taking the same task."

### 4b. Delegation + AGENT FOLLOW-UP (cross-agent work via issues)
An agent can route work to another human's agent by creating a correctly-addressed issue (assigned to that human, target agent-code bracket). Tracking is a **mandatory runner step, before claiming new work**: "Check delegated issues this agent routed to someone else, and leave AGENT FOLLOW-UP if anything changed." Receipt definition: "AGENT FOLLOW-UP — Posted on a delegated issue this agent routed to someone else when that issue's state has changed." That is the full spec — a change-detection ping on the issue itself (no notification routing defined; see GAPS). Routing-map self-registration rule for unknown agents: "If an agent is not listed yet, it may propose a unique agent code, ask its human to fill in the routing details, and leave those details as a comment on this routing map issue."

### 4c. Within-one-human orchestration (Visible Delegation)
For an agent driving another agent under the same human: run the delegate **in a named tmux session** ("hidden background agents drift for twenty minutes before anyone notices"), pass a goal prompt, monitor for "stuck loops, scope drift, destructive commands," and on completion **"the orchestrator runs the verification gates from the goal prompt itself"** before reporting success; "sessions get closed, not abandoned." Runbook 06 (Delegate and Verify): human touches only "the two decisions that need you: what 'done' means, and whether the diff is good." This is also the first optional Standing Skill in Open Engine ("visible-grok-claude-delegation").

### 4d. Continuity via memory (TaskFlow Work Log)
Cross-agent handoff through OB1, not through context: "Agent A recalls prior attempts/blockers/constraints → completes one TaskFlow step → writes compact work log → Agent B recalls the work log → continues without the raw transcript." "The handoff lives in OB1 as compact operational memory, not in one model's context window." Rules: recall required at start and resume; write-back required at completion, pause, or failure. Acceptance: "A second agent can continue from the work log without duplicated attempts"; "Confirmed project constraints outrank inferred step notes"; failures retrievable "but they do not become permanent rules." Handoffs may recall with `include_unconfirmed: true` (pending work-log notes are needed even before review).

---

## 5. THE TWO PAUSE CHANNELS — and WHO answers WHERE

Both park the issue in **Agent Needs Input**; they differ in *where the answer belongs* and *who can give it*:

| | **AGENT BLOCKED** | **AGENT HUMAN HOLD** |
|---|---|---|
| Trigger | "The missing answer belongs on this Linear issue" — a work-content question | "The answer belongs in the human's own agent thread/app: permissions, installs, account authority," automation setup, private context |
| Where asked | One specific question, as a comment on the same Linear issue | In the owner-human's own agent thread/app |
| Who answers | Whoever on the team has the answer (visible to all; typically the requester) | ONLY the owning human, privately |
| Ledger value | `blocked ISSUE-ID` | `holding ISSUE-ID` |
| Resume protocol | Runner checks blocked issues each run; "Resume only after the missing answer appears on the same Linear issue" → AGENT UNBLOCKED then AGENT RESUMED | Runner checks holds FIRST each run; "Resume only after the human answers in their own agent thread or the agent records AGENT HUMAN ANSWERED" (posted on the Linear issue to clear it publicly) → AGENT RESUMED |

Design intent: work questions stay public/auditable; authority/permission questions stay private. Troubleshooting explicitly corrects the failure mode "Agent asks runtime-permission questions in Linear" → use HUMAN HOLD. Both are resumable pauses, not failures ("Treat AGENT BLOCKED as a pause"). Runner order puts resumption **before** new work: holds → blocked → delegated follow-up → one new task — paused work outranks new work, and a resumed issue consumes the run ("finish it, and stop after this one issue").

---

## 6. SHARED CONTEXT: STANDING UPDATES, VERSIONS, APPROVAL-SUBSCRIPTION

How team-wide context propagates without meetings:

1. **One standing issue per shared context family** ("skills, SOPs, routing maps, voice guides, safety rules"), status `Standing`, addressed `[all agents]` (applies to every runtime) or to a specific agent code. Anti-pattern named: "Every standing update creates a pile of duplicate tickets" → "Update the version and changelog in place; agents compare versions during preflight."
2. **Version-diff propagation:** every runner run starts with "mandatory standing preflight: compare target versions for shared skills, SOPs, routing maps, voice guides, and safety rules before new task work." The ledger records what each agent runs: `Local context: <engine version>; <routing map version>`.
3. **AGENT APPLIED = installation receipt:** "Require AGENT APPLIED only after a runtime has actually installed or adapted the target version locally." So the standing issue's comment stream shows exactly which runtimes are on which version.
4. **Two propagation classes:**
   - **Mandatory** standing context (engine core, safety rules, routing map): applies to all, checked every run.
   - **Optional Standing Skills**: directory-not-auto-install ("Standard setup records where optional skills live. It does not install them, enable tools, or grant new authority"), human approval per runtime, **approval creates a subscription** ("that same approval covers future bug fixes and same-scope updates for that skill in that runtime"), auto-update only for same-scope changes during preflight ("Apply same-scope updates automatically; do not browse or install new optional skills during routine runs"), and **"Scope expansion asks again"**: "A skill update that adds new permissions, new external actions, new tools, or a different runtime boundary needs fresh approval."
5. **Ledger cross-reference:** `Optional skills: <none or skill-id@version subscribed>` — subscription state is public team knowledge.

This is Nate's team change-management system: canonical issue + version field + preflight diff + install receipt + consent-scoped auto-update.

---

## 7. THE STATUS LEDGER AS TEAM PRESENCE

"The ledger is how you know which agents are online, automated, blocked, holding, stale, or manual-only. It is a standing issue, not a task to close." Purpose sentence: "Every agent updates one status comment in place, so humans can see who is installed, automated, blocked, or stale."

- **One top-level AGENT STATUS comment per agent, updated in place** ("instead of adding heartbeat clutter"; troubleshooting: update "by comment id").
- Fields (exact): Agent, Human/operator, Runtime, Automation, Automation state (`installed | manual-required | blocked | paused`), Last heartbeat (ISO8601), **Last queue result** (`checking | none | observed ISSUE-123 | claimed | completed | blocked | holding | resumed | failed ISSUE-123`), Last successful run, Local context (versions), Optional skills, Notes.
- Written at run start (`checking`) and run end (result) — so a run is visible even mid-flight.
- **Team functions:** presence check before delegating ("If the target agent is not online in the status ledger, say that before relying on the handoff"); staleness detection (Last heartbeat vs now); blocked/holding visibility per agent; version audit; capability audit (subscribed skills). It is the entire "who's who and who's alive" surface — Nate: "The ledger is the boring part that makes the exciting part operable."
- Semantics discipline: "Use blocked ISSUE-ID for Linear-answerable blockers, holding ISSUE-ID for human-thread holds, and completed ISSUE-ID only after the task is actually done."
- The first AGENT STATUS comment is created **manually by/with the human at onboarding**, then the runner takes over updating it.

---

## 8. REVIEW & APPROVAL ROLES (who judges what)

| Decision | Who | Where |
|---|---|---|
| Task work quality (when "review, QA, approval, inspection, or publishing is needed") | A human — the agent leaves AGENT DONE and moves to **Agent Review** instead of Agent Done | Linear |
| Blocked-question answers | Any human with the answer (public) | Same Linear issue |
| Runtime permissions, installs, account authority | The owning human ONLY | Their own agent thread |
| Optional skill install/adaptation (first time) | The runtime's human | Their own agent thread; receipt on canonical skill issue |
| Skill scope expansion / new authority / runtime change | Fresh approval from the human, every time | Same |
| Publishing & customer-facing changes | "Human approval is required" (routing map rule); grant is issue-level | Linear issue body |
| Outward/destructive actions (email, deploy, billing, credentials, delete) | Never without explicit issue-level grant | Linear issue body |
| Agent-written memory → instruction-grade | Human confirm (only path; DB-enforced); review actions logged with before/after snapshots | OB1 review queue/dashboard |
| Repo review lessons (code-review memory) | "Maintainer review queue"; "Maintainer corrections can supersede older lessons" | OB1 |
| Extracted new skills (Session-to-Skill Extractor) | Human review — drafts "land somewhere for review — never silently into the live library" | Local/skill library |
| Self-authored PR merges | The human's own review discipline via skill; "any failing check or unresolved review finding halts the merge" | GitHub |
| Keep/change/pause/retire an agent | The agent's owner, via Maintenance Loop, backed by replay-pack evidence | Maintenance record with agent config |
| Final go/no-go on anything | "Humans still own judgment" — final judgment is on the human-only list, always | — |

---

## 9. MAINTENANCE RITUALS AND TRIGGERS (team-relevant summary)

- **Cadence:** trigger-driven, never scheduled. Four trigger families (Upstream change / Scope creep / Rising human cost / Quiet failure); "one agent and one signal" per pass; "finish this pass first, then repeat the loop for the next one."
- **Ritual:** (1) job sentence with the 5-part template ("…with [this human review] before [this consequence]"); (2) read ~10 real runs, 3+ repeated corrections = harness signal; (3) walk 7 surfaces in fixed order (Job→Diet→Memory→Tools→Reach→Proof→Value), verdicts ok/drifting/broken; (4) replay pack 5–20 known cases incl. "at least one high-risk case where the only correct move was to stop and escalate," baseline before changes; (5) **delete before you add** — additions must fail-without/pass-with; (6) one decision + 7-field record stored with the config.
- **Who does what:** the human owner picks the agent+trigger, writes the job sentence, makes the decision; the AI inventories, restates the trigger as a checkable question, reads runs, proposes fixes. The loop is written for a single owner — no team roles (see GAPS).
- **Engine-side hygiene rituals** (from the runner + troubleshooting): every run = ledger heartbeat + standing preflight + hold/block sweep + delegated follow-up; smoke tests are the onboarding ritual per teammate; "Fix the contract, then rerun the smoke test" is the debugging ritual.
- **Self-improvement rituals:** Session-to-Skill Extractor at "wrap up" (RECURRING/NON-OBVIOUS/CODIFIABLE bar; "most sessions yield nothing, and that's correct"); Flywheel runbook "runs under every other runbook"; Life Engine pattern: one approved improvement per week, logged.

---

## 10. GAPS — what Nate leaves UNSPECIFIED about teams (we must design these)

**GAP 1 — Human notification/paging.** No mechanism tells a human their agent is blocked/holding/failed or that an Agent Review item awaits them. The ledger and statuses are pull-only; nothing pushes. (His own Life Engine uses Telegram/Discord pushes, but that's personal, not part of the team spec.)

**GAP 2 — Who reviews Agent Review.** "Completed work that still needs human judgment" — but requester vs owner vs a third QA person is never assigned. No reviewer field, no review SLA, no rejection/rework receipt (there is no AGENT REWORK token; presumably the reviewer comments and re-queues, unspecified).

**GAP 3 — Who may answer AGENT BLOCKED.** Publicly visible, but no rule on authoritative answerers; two teammates could give conflicting answers on the same issue. No conflict-resolution rule.

**GAP 4 — Governance of shared context itself.** Who may bump a standing issue's version, who approves changes to the routing map or safety rules, whether a mandatory-context change needs any review before every agent preflights it in — all unspecified. Mandatory standing updates propagate with NO approval gate (only optional skills have the approval-subscription model). A bad edit to `[all agents]` safety rules propagates to the whole team on next preflight.

**GAP 5 — No engine-admin role model.** No owner/admin/member roles for the engine; anyone in the Linear team can create/modify anything. "Private" setup issues rely on Linear's own visibility (personal projects/permissions), never specified. Agent identity = the human's Linear account; receipts are attributed only by agent-code text inside comments — no authentication of who actually wrote AGENT DONE.

**GAP 6 — Delegation depth and load.** Nothing on delegation chains (A→B→C), circular delegation, how many delegated tasks one may route to a colleague's agent, prioritization between delegated-in work and own work (only "oldest eligible"), or what an agent should DO after AGENT FOLLOW-UP notes a change (re-plan? notify its human? consume the result?). Follow-up is a change-detection receipt with no defined consumer.

**GAP 7 — Deadlines, SLAs, escalation, offline targets.** No due-date semantics, no timeout on blocked/holding issues, no escalation path when an answer never comes, no stale-claim reaping (an agent that dies after AGENT CLAIMED leaves a locked issue; only human troubleshooting recovers it). If the target agent is offline the rule is just "say that before relying on the handoff" — no fallback routing, no re-assignment protocol.

**GAP 8 — Team-shared Brain.** Open Engine's team path never integrates Open Brain at team level. OB1 is personal; the shared-MCP primitive is point-to-point slice-sharing (spouse/collaborator scale) with manual roles and key rotation. The memory schema HAS team/workspace/organization visibility scopes and a promote-with-review rule ("Personal or channel memory never auto-promotes to team or workspace scope"), but there is no described workflow for a team-scoped workspace: who reviews pending memories in a shared workspace, whether review rights follow memory scope, multi-reviewer semantics — none specified (review actions carry `actor_id` but no role/permission model).

**GAP 9 — Cross-owner maintenance.** The Maintenance Loop is written for the agent's owner. Nothing on team-level fleet review, whether a teammate/lead may audit or pause someone else's agent, or shared replay packs.

**GAP 10 — Offboarding.** Onboarding is specified (one at a time, context + ledger comment + smoke test); removing a teammate/agent (revoking Linear access, retiring the agent code, cleaning the ledger, reassigning open claimed issues, revoking skill subscriptions) is not — only shared-MCP key rotation covers revocation, and only for memory slices.

**GAP 11 — Throughput and scheduling.** One task per run, heartbeat by "your runtime's scheduler or a cron job" — cadence, concurrency across a team's agents, queue fairness, and priority (beyond FIFO) are all left open.

**GAP 12 — Human↔human protocol generally.** Beyond the `requester` field and naming meetings, no channel, no rituals (standups, review rotations), no way to discuss an issue that isn't agent-addressed. Nate's model implicitly assumes the team already has its human communication fabric; the Engine only formalizes the human↔agent and agent-visible surfaces.

---

## 11. Design cues worth carrying into our own team design (interpretive)

- Ownership is the load-bearing wall: every agent has exactly one human; every cross-boundary request travels via that human's queue. Any Nextcloud/Deck adaptation should preserve "route to the owner, address the agent" as separate fields (assignee = human; agent code = executor).
- The two-channel pause split (public work question vs private authority question) is the single most transferable idea — it keeps PII/permission matters out of the shared surface by construction.
- Presence = one self-updated record per agent, in-place, with machine-readable last-result tokens. Cheap, greppable, no event infrastructure.
- Version-diff preflight + install receipts (AGENT APPLIED) is a full config-management system in issue comments; approval-creates-subscription with scope-expansion-re-asks is a consent model worth copying verbatim.
- Everything Nate leaves as a GAP above is exactly the layer a real multi-user product (like ours) must add: notifications, reviewer assignment, roles/ACLs, SLAs/escalation, offboarding, and team-scoped memory governance.
