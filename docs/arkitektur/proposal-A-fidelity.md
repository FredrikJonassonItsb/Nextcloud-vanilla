# Proposal A — Maximum Fidelity: ITSL Open Stack on Nextcloud

**Design angle:** change as little of Nate B. Jones's Open Stack as possible. Deck replaces Linear 1:1 (board/stacks/cards = team/statuses/issues). Nextcloud Talk replaces Slack capture 1:1 (room webhook bot → thoughts INSERT → threaded confirmation). OB1 is self-hosted per person exactly as the OB1 kubernetes-deployment integration ships it, translated to docker compose. Every status name, receipt token, title-bracket convention, ledger field, and smoke test comes verbatim from the Open Engine spec. Where the original design leaves a choice, we take the choice Nate documented, because provenness is the whole point of this proposal.

The only genuinely new code is (1) a ~200-line Talk capture webhook service that is a line-for-line port of the shipped Slack `ingest-thought` function, and (2) a ~150-line shell wrapper around Deck's OCS REST API so the queue runner has deterministic Deck operations. Everything else is configuration, vendored OB1 source, and skill files.

---

## 1. Component diagram (docker services)

All services live in one `docker-compose.yml` in a new repo **`itsl-open-stack`** (sibling of `Nextcloud-vanilla`), deployed on one ITSL Docker host (start on the dev-server class machine, e.g. next to dev15; see §10 for the move to production).

```
                        ITSL humans (4 laptops)
                        Claude Code interactive (Max subscription)
                        each with: open-agent-engine skill + own brain MCP
                                  │
        ┌─────────────────────────┼──────────────────────────────┐
        │                         │                              │
        ▼                         ▼                              ▼
┌─────────────────┐    ┌──────────────────────┐     ┌─────────────────────┐
│ itsl.hubs.se    │    │   Docker host: itsl-open-stack             │
│ (Nextcloud)     │    │                                            │
│                 │    │  proxy (caddy) :443  brain.itsl.se         │
│  Deck app       │    │   ├── /reb    → ob1-mcp-reb    :8001       │
│  board:         │◄───┤   ├── /atlas  → ob1-mcp-atlas  :8002       │
│  "Agent Engine" │OCS │   ├── /ada    → ob1-mcp-ada    :8003       │
│                 │REST│   ├── /marvin → ob1-mcp-marvin :8004       │
│  Talk app       │    │   └── /team   → ob1-mcp-team   :8005       │
│  5 capture rooms│    │                                            │
│  + Talk bot per │───►│  capture-bot :8100  (Talk webhook target)  │
│  room (webhook) │    │                                            │
└─────────────────┘    │  ob1-db-reb     ankane/pgvector:v0.5.1     │
        ▲              │  ob1-db-atlas   ankane/pgvector:v0.5.1     │
        │              │  ob1-db-ada     ankane/pgvector:v0.5.1     │
        │ Deck OCS     │  ob1-db-marvin  ankane/pgvector:v0.5.1     │
        │ (app pwd     │  ob1-db-team    ankane/pgvector:v0.5.1     │
        │  per human)  │                                            │
        │              │  runner-reb     claude-code headless, cron │
        └──────────────┤  runner-atlas   claude-code headless, cron │
                       │  runner-ada     claude-code headless, cron │
                       │  runner-marvin  claude-code headless, cron │
                       │                                            │
                       │  backup (pg_dump nightly, all 5 dbs)       │
                       └────────────────────────────────────────────┘
                                  │
                                  ▼ outbound only
                       Anthropic API (headless runners)
                       OpenRouter API (embeddings + metadata LLM)
```

### Exact container list

| Service | Image | Ports | Volumes | Notes |
|---|---|---|---|---|
| `ob1-db-reb` / `-atlas` / `-ada` / `-marvin` / `-team` | `ankane/pgvector:v0.5.1` | internal 5432 | named volume `ob1_db_<code>` | init.sql mounted at `/docker-entrypoint-initdb.d/init.sql` (same mechanism as the k8s ConfigMap) |
| `ob1-mcp-reb` / `-atlas` / `-ada` / `-marvin` / `-team` | `itsl/openbrain-mcp-server:1.0.0` (built from OB1's `denoland/deno:2.3.3` Dockerfile, vendored `index.ts` + `deno.json`) | 8001–8005 → 8000 | none (stateless) | env per instance: `DB_HOST=<its db>`, `MCP_ACCESS_KEY=<per-brain key>`, `EMBEDDING_API_BASE=https://openrouter.ai/api/v1`, `EMBEDDING_MODEL=openai/text-embedding-3-small`, `CHAT_MODEL=openai/gpt-4o-mini`, `OPEN_BRAIN_CITATION_BASE_URL=https://brain.itsl.se/<code>/thoughts` |
| `capture-bot` | `itsl/talk-capture:1.0.0` (Node or Deno, ~200 lines) | 8100 | none | one container, routing table room→brain (see §5) |
| `runner-reb` / `-atlas` / `-ada` / `-marvin` | `itsl/agent-runner:1.0.0` (node:22 + `@anthropic-ai/claude-code` CLI + `deck-cli.sh` + cron) | none | ro mount of `skills/` | one queue check per cron tick; see §7 |
| `proxy` | `caddy:2` | 443 | caddy data | TLS for `brain.itsl.se`; path prefix → brain. Auth stays the OB1 `x-brain-key`/`?key=` model — Caddy does TLS only |
| `backup` | `postgres:16-alpine` + cron | none | `backups/` bind mount | nightly `pg_dump` of all 5 dbs, 30-day rotation |

Nextcloud itself (Deck, Talk) is **not** in this compose file — it is the existing `itsl.hubs.se` instance. The stack only talks to it over HTTPS (OCS REST + Talk bot webhooks), exactly the way Nate's stack talks to linear.app over MCP. This keeps the queue on the platform the team already operates with the `itsl` CLI, and keeps the Open Stack deployable/movable independently.

**Not shipped in v1 (deliberate, fidelity to "smallest system that works"):** OB1 dashboards. The Next.js dashboard requires the `open-brain-rest` gateway whose source is not in OB1; the SvelteKit one requires Supabase Auth. Nate's own core docs say the manual edit path is the database itself — for us that is `psql` into the brain containers, plus the MCP tools. Dashboards are a later optional milestone, not part of the engine.

---

## 2. Data model decisions

**One schema, five identical instances.** Every brain (4 personal + 1 team) runs the **production OB1 schema from `docs/01-getting-started.md`**, not the minimal k8s `init.sql`. Rationale: the k8s init.sql has `id BIGSERIAL` and no `updated_at`, which makes the shipped `fetch` tool error on a fresh install (known OB1 defect). The core-docs schema is the one Nate actually runs. Our `init.sql` is therefore, verbatim from OB1 core docs:

- `thoughts` table: `id uuid default gen_random_uuid() primary key, content text not null, embedding vector(1536), metadata jsonb default '{}', created_at timestamptz default now(), updated_at timestamptz default now()` + `content_fingerprint TEXT` with partial unique index
- Indexes: HNSW `vector_cosine_ops` on embedding, GIN on metadata, btree `created_at desc`
- `update_updated_at()` trigger
- `match_thoughts(query_embedding, match_threshold, match_count, filter)` — cosine, with the JSONB containment filter
- `upsert_thought(p_content, p_payload)` — SHA-256 fingerprint dedupe, metadata merge on conflict
- No RLS policies needed (no Supabase roles; each instance is single-tenant behind its own `MCP_ACCESS_KEY` — **isolation is at the instance boundary**, which is the strongest form and the one the k8s integration uses)

One adaptation to the vendored k8s `index.ts` (mechanical, ~20 lines): its `capture_thought` does a plain `INSERT`; we point it at `upsert_thought` + embedding update, matching the production server's dedupe behavior documented in `ob1-server-primitives`. Everything else in `index.ts` — the 6 tools (`search`, `fetch`, `search_thoughts`, `list_thoughts`, `thought_stats`, `capture_thought`), the verbatim metadata-extraction prompt (people / action_items / dates_mentioned / topics / type ∈ observation·task·idea·reference·person_note), the stateless per-request McpServer, `x-brain-key`/`?key=` auth, the Claude-Desktop Accept-header patch, CORS — ships unmodified.

**Metadata conventions (`metadata.source`):**

| source value | written by |
|---|---|
| `mcp` | `capture_thought` from any client (OB1 default) |
| `talk` | capture-bot; also sets `talk_id` (dedupe key), `talk_room`, `talk_actor` |
| `claude_code_ambient` | auto-capture Stop hook (M8) |
| `agent_memory` | agent-memory writeback (M8) |

**Agent-memory sidecar (Milestone 8, not v1):** the full `schemas/agent-memory/schema.sql` (8 tables: `agent_memories`, source_refs, artifacts, relations, review_actions, recall_traces, recall_items, audit_events, with the DB CHECK that `can_use_as_instruction` requires `user_confirmed`/`imported` provenance) applied to each personal brain, plus the `agent-memory-api` Hono app ported from Supabase Edge Function to a Deno route inside the same mcp-server container (it already shares the deps: hono, zod; swap `@supabase/supabase-js` calls for the deno-postgres pool). This is the governed "agents train over time" layer — see §8.

**Deck holds no data model of ours.** Cards, stacks, labels, and comments are used exactly as Linear issues/statuses/labels/comments are in the Open Engine spec. No Nextcloud app development, no extra tables in the Nextcloud DB. The card history + comments are the audit trail, as in the original.

---

## 3. Deck queue mapping (Open Engine → Deck, 1:1)

One Deck board on itsl.hubs.se: **`Agent Engine`** (the "Team Agent Engine" variant — team collaboration from day one), shared with users `rebecca`, `fredrik`, `sandra`, `mattias` (edit + share permission, no manage for non-admins). Nate's "Linear team + project" pair collapses to the single board — Deck stacks are per-board exactly as Linear statuses are per-team, so the mapping is clean.

### 3.1 Statuses → stacks (exact order, exact names)

| Open Engine status (Linear) | Deck stack (create in this order) | Semantics (unchanged) |
|---|---|---|
| Standing | `Standing` | durable setup, ledger, routing map, skill directory — versioned context, never "completed" |
| Agent Todo | `Agent Todo` | finite tasks waiting for the target operator's agent |
| Agent Working | `Agent Working` | the claim lock; agent must leave `AGENT CLAIMED` |
| Agent Needs Input | `Agent Needs Input` | paused: blocked (answer belongs on the card) or human hold (answer belongs in the human's own agent thread) |
| Agent Review | `Agent Review` | complete but needs human judgment/QA/approval |
| Agent Done | `Agent Done` | complete, receipt left, no review needed |

"Make Agent Done a completed-category status" maps to: when a card lands in `Agent Done`, the runner additionally sets the Deck card **done** flag (Deck ≥1.12 `done` datetime via `PUT .../cards/{id}`), and cards done >30 days are archived (`archived: true`) by the runner during preflight. That reproduces Linear's completed-category + keeps the board readable.

### 3.2 Label, titles, assignees, agent codes

- **Label:** exactly `agent-instructions`, created once on the board. The runner filters on this spelling — verbatim rule from the spec.
- **Agent codes:** `reb` (Rebecca), `atlas` (Fredrik), `ada` (Sandra), `marvin` (Mattias). One runtime per human in v1, so no `-codex`/`-claude` suffixes; if a second runtime is ever added it becomes `atlas-code` etc. per Nate's multi-runtime rule.
- **Title pattern (verbatim grammar):** `[agent instructions][<agent-code>][task] <outcome>` for tasks; `[agent instructions][all agents][standing_skill|standing_status] ...` for standing cards. Examples:
  - `[agent instructions][atlas][task] Draft release notes for hubs_arende v1.3`
  - `[agent instructions][ada][task] Summarize this week's dev15 test findings`
  - `[agent instructions][all agents][standing_status] Open Agent Engine status ledger`
- **Assignee:** Deck card assigned user = the **human who owns the target agent** (Nextcloud uid). Team-path rule verbatim: "Assign cross-agent work to the human who owns the target agent, not to yourself."
- **Card description** uses the task-issue body template verbatim: Requester / Desired outcome / Context / Sources / Do / Acceptance criteria / Output handoff / Boundaries.

### 3.3 Eligibility rule (runner filter, unchanged)

A card is eligible for agent `atlas` iff: stack = `Agent Todo` AND label `agent-instructions` AND title starts `[agent instructions]` AND second bracket = `atlas` AND assigned user = `fredrik`. Oldest eligible first. Process exactly one per run.

### 3.4 FULL receipt vocabulary → Deck card comments

Receipts are Deck card comments via the OCS Comments API (`POST /ocs/v2.php/apps/deck/api/v1.0/boards/{b}/stacks/{s}/cards/{c}/comments` — wrapped by `deck-cli.sh`). Tokens are byte-identical to the spec; every comment starts with the token, then the agent code, then detail:

| Receipt token | When (unchanged from spec) | Deck action paired with it |
|---|---|---|
| `AGENT CLAIMED` | right after moving card to `Agent Working` — the claim lock | card moved Todo→Working, then comment, then **re-read the card** |
| `AGENT DONE` | scoped work finished | move to `Agent Done` (no judgment needed) or `Agent Review` (judgment needed) |
| `AGENT BLOCKED` | missing answer belongs on this card; ask ONE specific question | move to `Agent Needs Input` |
| `AGENT UNBLOCKED` | the answer arrived on the same card | posted immediately before `AGENT RESUMED` |
| `AGENT HUMAN HOLD` | answer belongs in the human's own Claude Code session (permissions, installs, account authority) | move to `Agent Needs Input`; ask in the human's own thread |
| `AGENT HUMAN ANSWERED` | human answered the hold in their own thread | clears the hold |
| `AGENT RESUMED` | continuing a paused card, after UNBLOCKED or HUMAN ANSWERED | move back to `Agent Working` |
| `AGENT FAILED` | unrecoverable failure only; record last safe step + retry count | stays in `Agent Working`, ledger `failed CARD-ID` |
| `AGENT APPLIED` | runtime actually installed/adapted a standing context version locally | on the standing card |
| `AGENT SKILL SUBSCRIBED` | human approved first install of an optional standing skill | on the canonical skill card |
| `AGENT SKILL INSTALLED` | runtime actually installed/adapted it locally | on the canonical skill card |
| `AGENT SKILL UPDATED` | subscribed skill received a same-scope local update | on the canonical skill card |
| `AGENT SKILL DECLINED` | human declined/deferred the optional skill | on the canonical skill card |
| `AGENT FOLLOW-UP` | a delegated card this agent routed to someone else changed state | on the delegated card |
| `AGENT STATUS` | the single ledger comment each agent owns, updated **in place** | on the ledger card, edited via `PUT .../comments/{id}` |
| `AGENT AUTOMATION READY` | after install + smoke test of the core context (from the setup-issue template) | on the private setup card |

Card IDs replace issue IDs everywhere: ledger `Last queue result` values are verbatim `checking | none | observed CARD-123 | claimed CARD-123 | completed CARD-123 | blocked CARD-123 | holding CARD-123 | resumed CARD-123 | failed CARD-123`, where `CARD-123` is the Deck card id (stable, present in card URLs `apps/deck/board/{b}/card/{123}`).

### 3.5 Standing cards (created by the M4 bootstrap script)

1. `[agent instructions][all agents][standing_skill] Install ITSL Open Agent Engine core context v1` — the setup card. Per the spec's privacy note, org charts / customer context / secrets / private skill bodies do NOT go here (Deck board is team-visible): they live in each person's local runtime context file. The card lists: what the engine is for, ledger card id, routing map card id, optional skill directory card id, required skills to install with local paths, smoke-test expectations, receipt meanings, and requires `AGENT AUTOMATION READY` after install.
2. `[agent instructions][all agents][standing_status] Open Agent Engine status ledger` — first comment per agent posted manually before automation exists (verbatim `AGENT STATUS` format: Agent / Human/operator / Runtime / Automation / Automation state / Last heartbeat / Last queue result / Last successful run / Local context / Optional skills / Notes). Runner edits its own comment in place by comment id.
3. `[agent instructions][all agents][standing_skill] Agent routing map v1`:

```
## Agent routing map

Rebecca
- Deck assignee: rebecca
- Agent codes: reb
- Route to Rebecca for: <her ownership area — filled at onboarding>

Fredrik
- Deck assignee: fredrik
- Agent codes: atlas
- Route to Fredrik for: platform architecture, hubs_arende/hubs_start backend, deploys via itsl CLI

Sandra
- Deck assignee: sandra
- Agent codes: ada
- Route to Sandra for: <ownership area>

Mattias
- Deck assignee: mattias
- Agent codes: marvin
- Route to Mattias for: <ownership area>

Rules:
- If assigning work to another person's agent, assign the card to that person.
- If the target agent is not online in the status ledger, say that before relying on the handoff.
- Human approval is required for publishing, deploys, and customer-facing changes.
```

4. `[agent instructions][all agents][standing_skill] Optional standing skill directory v1` — directory card with the verbatim rules ("part of standard setup / not automatically installed / first approval subscribes / scope expansion asks again"), one canonical Standing card per optional skill listed in §9.

### 3.6 Private context packet (per person, local — verbatim template, ITSL values)

`~/.claude/skills/open-agent-engine/SKILL.md` on each laptop AND baked read-only into each runner container. Fredrik's instance:

```
---
name: open-agent-engine
description: Route assigned Deck agent tasks through the ITSL Open Agent Engine queue on itsl.hubs.se.
version: 1
---

Agent code: atlas
Human/operator: Fredrik Jonasson
Deck board: Agent Engine (board id <B>)
Agent label: agent-instructions
Status ledger card: CARD-<L>
Optional standing skill directory: CARD-<D>
Subscribed optional skills: none
Open Brain: https://brain.itsl.se/atlas (x-brain-key from local env ATLAS_BRAIN_KEY)
Team Brain: https://brain.itsl.se/team (x-brain-key from local env TEAM_BRAIN_KEY)
Private sources: C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla; <approved folders>

Rules:
- (all 20 rules from the Open Engine starter private context file, verbatim, with
  "Linear" → "the Deck board" and "issue" → "card")
- Before task work, recall relevant context from my Open Brain (search_thoughts) and
  the Team Brain; after task work, capture a compact receipt thought to my Open Brain.
- Ask before publishing, emailing, posting publicly, deploying (itsl CLI deploy/update),
  changing billing, changing credentials, deleting destructive data, or making
  customer-facing changes.
- Never read, copy, or capture PII from Hubs case data (orosanmälningar, ärenderum)
  into any brain or card.
```

The two brain rules are the only additions to Nate's rule list — and they are his own composition rules from the Open Stack field guide ("Open Engine can read that context before claiming a task, then write back compact receipts after work finishes"), just made concrete. The PII rule is ITSL-mandatory (see §9).

---

## 4. Capture flow (Talk replaces Slack, port of `ingest-thought`)

Five Talk rooms on itsl.hubs.se, one bot each:

| Room | Feeds brain | Members |
|---|---|---|
| `🧠 Reb capture` | ob1-db-reb | rebecca only |
| `🧠 Atlas capture` | ob1-db-atlas | fredrik only |
| `🧠 Ada capture` | ob1-db-ada | sandra only |
| `🧠 Marvin capture` | ob1-db-marvin | mattias only |
| `🧠 Team brain` | ob1-db-team | all four |

**Mechanism** (Talk bot framework, NC 27+/Talk 17+, available on Hubs): `occ talk:bot:install "Open Brain" <shared-secret> https://brain.itsl.se/capture/webhook` then `occ talk:bot:setup <bot-id> <room-token>` per room. Talk sends signed webhooks (HMAC-SHA256 over random header + body with the shared secret) for every room message.

**capture-bot logic — the §5.3 generic contract, line-for-line from the Slack function:**

1. Verify HMAC signature (replaces Slack's implicit URL trust; strictly better).
2. Filter: only `Create` activity of a chat `message`, only from configured room tokens, skip messages from the bot itself, skip empty/system messages. Room token → brain routing table from env (`CAPTURE_ROUTES=token1:reb,token2:atlas,...`).
3. **Dedupe:** query target brain's `thoughts` where `metadata @> {"talk_id": "<message-id>"}`; if found → 200 OK (Talk retries webhooks like Slack does).
4. `Promise.all`: OpenRouter embedding (`openai/text-embedding-3-small`) + metadata extraction (`openai/gpt-4o-mini`, the verbatim OB1 system prompt).
5. `INSERT INTO thoughts (content, embedding, metadata)` **directly into that brain's Postgres** (capture-bot has DB creds for all five — it is the trusted service, exactly like the Slack function's service-role key) with `metadata = {...extracted, source: "talk", talk_id, talk_room: token, talk_actor: userId}`.
6. Threaded confirmation via the Talk Bot API (`POST /ocs/v2.php/apps/spreed/api/v1/bot/{token}/message` with `replyTo`), verbatim format:

```
Captured as *person_note* — career, consulting
People: Sarah
Action items: Check in with Sarah about consulting plans
```

**Canonical smoke test (unchanged from OB1):** type in `🧠 Atlas capture`: *"Sarah mentioned she's thinking about leaving her job to start a consulting business"* → threaded `Captured as person_note — ...` reply within ~10 s → one row in `ob1-db-atlas` → in Claude Code: "What did I capture about Sarah?" retrieves it.

The team room is capture-only shared memory in v1: anything anyone types there lands in the team brain, searchable by all four agents. Cross-person routing of *work* never goes through capture — work moves through the Deck queue (that is the Engine/Brain boundary in the original design and we keep it).

---

## 5. Per-person brain isolation + team sharing

**Isolation = instance isolation** (the strongest option in OB1's own `rls` vs `shared-mcp` primitives; the k8s deployment is single-tenant by construction). Five databases, five MCP servers, five distinct 64-hex `MCP_ACCESS_KEY`s (generated by Fredrik: `openssl rand -hex 32` ×5). Rebecca's key does not open Fredrik's brain — there is no cross-tenant SQL to get wrong, no RLS policy to audit. Personal brains contain private memory; nobody, including admins, browses another person's brain as a matter of policy (Fredrik holds keys in the ops vault for disaster recovery only).

**Team sharing = the `shared-mcp` primitive, instance flavor:** the team brain is a separate endpoint with a separate key that all four humans and all four runners hold. The 3-layer scoped-access model maps to: (1) separate endpoint `brain.itsl.se/team`, (2) separate `TEAM_BRAIN_KEY`, (3) the boundary test from the primitive becomes our smoke test — each personal key MUST 401 against `/team` and against every other personal brain, and `TEAM_BRAIN_KEY` MUST 401 against all personal brains.

**Client wiring (per person, Claude Code):**

```bash
claude mcp add --transport http open-brain  https://brain.itsl.se/atlas --header "x-brain-key: <ATLAS_KEY>"
claude mcp add --transport http team-brain  https://brain.itsl.se/team  --header "x-brain-key: <TEAM_KEY>"
```

Claude Desktop / mobile: the `?key=` connection-URL form, per OB1 docs. Two connectors per person, 12 tools total — well under the tool-audit's ~20-tool optimization threshold.

**What goes where (memory policy, from the field guide's evidence/instruction split):**
- Personal brain: working notes, session summaries, personal preferences, drafts, aiception discoveries. Agent-written = evidence by default.
- Team brain: decisions that bind the team, Hubs domain facts, routing/ownership facts, retro outcomes, standing skill changelog summaries. Write to team brain deliberately (type in the team room, or explicitly `capture_thought` on the team connector) — "personal or channel memory never auto-promotes to team or workspace scope" (agent-memory doctrine, adopted as a rule from day one even before the agent-memory schema lands in M8).

---

## 6. Agent runtime story

**Two runtimes per agent identity, one agent code** — exactly Nate's model (interactive sessions + a scheduled queue runner are the *same* agent identity; the runner is "one instruction your agent repeats").

### Interactive (the human's daily driver)
Claude Code on each laptop under that person's **Claude Max subscription**. Skills installed at `~/.claude/skills/` from the `itsl-open-stack/skills/` git repo (git pull = skill update; the standing-skill preflight compares the version in the standing card to the local file's frontmatter version). Humans trigger queue runs manually at first ("run one Open Engine queue check") — this is Nate's stated path: hand-triggered runs before scheduled ones.

### Headless queue runners (the heartbeat)
Four containers `runner-{reb,atlas,ada,marvin}`. Each:
- Base: `node:22-slim` + `npm i -g @anthropic-ai/claude-code` + `jq`, `curl`, `deck-cli.sh`.
- Auth: **Anthropic API key** (`ANTHROPIC_API_KEY`), NOT the Max subscription — subscriptions are for interactive personal use; headless containers use metered API keys Fredrik creates in the Anthropic Console, one per runner (`itsl-runner-reb` …) so cost is attributable per agent. Model: `claude-sonnet-4-5` class for runner ticks (queue checks are cheap-shaped work; a task card may say "escalate to your human" for heavy work — the runner's job is the loop, not heroics).
- Deck access: the owning human's **Nextcloud app password** (Settings → Security → new app password `agent-runner-<code>`), so all card moves/comments happen as that human's account — matching Linear MCP acting as the connected human's account. Receipts always carry the agent code in the comment body, so authorship is unambiguous.
- Brain access: `x-brain-key` for its own brain + the team brain.
- **Schedule:** cron `*/30 * * * *` runs `claude -p "$(cat /runner/queue-run.md)" --allowedTools "Bash(deck-cli.sh *) mcp__open-brain__* mcp__team-brain__*" --max-turns 40`. `queue-run.md` is the Open Engine queue-run prompt **verbatim** (the full `<task>/<order>/<receipts>/<boundaries>` block), with "Linear"→"Deck board", "issue"→"card", and the two brain-recall/receipt steps added. A flock lockfile prevents overlapping ticks. Runner logs to stdout → `docker logs`, and every tick ends by updating the `AGENT STATUS` ledger comment in place — the ledger IS the monitoring surface, per the spec.
- `deck-cli.sh` subcommands (deterministic, no LLM judgment in the transport): `list-eligible <agent-code>`, `get-card <id>`, `move-card <id> <stack>`, `comment <id> <text>`, `edit-comment <id> <commentId> <text>`, `set-done <id>`, `archive-done-older-than 30d`. Thin curl wrappers over `/ocs/v2.php/apps/deck/api/v1.1/...` and the Deck comments API.

### API keys inventory (all created by Fredrik)

| Key | Count | Used by |
|---|---|---|
| Anthropic API keys `itsl-runner-<code>` | 4 | headless runners |
| Claude Max subscriptions | 4 | interactive Claude Code (already owned per person) |
| OpenRouter key `itsl-open-brain` | 1 | all 5 mcp-servers + capture-bot (embeddings ~$0.02/M tokens, metadata gpt-4o-mini; expected <$5/month total at team scale) |
| `MCP_ACCESS_KEY` 64-hex | 5 | one per brain |
| Talk bot shared secret | 1 | capture-bot HMAC |
| Nextcloud app passwords `agent-runner-<code>` | 4 | runner Deck access |
| Postgres passwords | 5 | one per brain db (only capture-bot + own mcp-server hold them) |

All secrets live in `/opt/itsl-open-stack/.env` on the Docker host (mode 600, root) and in Fredrik's credential tracker (the OB1 credential-tracker convention, one row per key). No secret ever appears in a Deck card, a Talk message, a brain thought (the M8 writeback firewall enforces this mechanically), or the git repo.

---

## 7. Skill library layout for ITSL's actual work

Repo `itsl-open-stack/skills/` (git = distribution; the Deck standing cards = version announcement + subscription state; `AGENT APPLIED`/`AGENT SKILL *` receipts = install audit). Every skill follows the OB1 SKILL.md format — YAML frontmatter with **single-line description ≤1024 chars** (the multi-line `|` block silently breaks Claude Code routing — known OB1 caveat), semver, trigger conditions, process, verification.

### Mandatory core (installed for everyone at onboarding, listed in the setup card)

| Skill | Origin | ITSL adaptation |
|---|---|---|
| `open-agent-engine` | Open Engine private context file | per-person agent code + card ids (§3.6) |
| `deck-queue` | new (thin) | documents `deck-cli.sh` usage + eligibility rule so interactive sessions and runners move cards identically |
| `auto-capture` | OB1 `@jaredirish` v1.0.0 | session-end protocol: ACT NOW items + one session summary → own brain; verbatim capture templates |
| `aiception` | OB1 (claudeception→aiception) v2.0.0 | session-to-skill extraction; extracted skills land as a PR to `skills/` + a proposal card in the optional directory — "never lands silently in live library" |
| `meeting-synthesis` | Open Skills, Research & Thinking | Swedish-language meetings; decisions-with-decider, actions-with-owner, said-vs-inferred rule; durable outcomes → team brain |

### Optional standing skills (directory card; install on approval only — "directory, not auto-install")

ITSL-specific, each a canonical Standing card + a folder in `skills/`:

| Skill | What it encodes (ITSL's actual work) |
|---|---|
| `hubs-deploy-runbook` | the `itsl` CLI lifecycle (start/stop/update/deploy), dev15 SSH access patterns, dev15-reset.sh, version model — **draft-only for prod**: agent prepares, human runs the deploy (Reach boundary) |
| `nextcloud-app-conventions` | PHP OCS app patterns from `Nextcloud-vanilla`: info.xml/routes.php/Controller/Mapper structure, OCS API conventions, appinfo versioning |
| `hubs-frontend-taste` | Vue 2.7 constraints for hubs_start, component conventions, NcComponents usage |
| `hubs-local-tests` | run hubs_start `npm test` on Windows; hubs_arende phpunit via composer:2 docker image (already in team memory — codify it) |
| `testing-runbook-creator` + `page-testing-memory` | Open Skills Testing & Quality pair, pointed at the hubs repos' `docs/testing-runbook.md` |
| `stakeholder-update-email` | Open Skills Agent Operations; gate: send nothing if nothing visible changed; explicit send confirmation |
| `release-briefing` | "brief me up on <release>" for Nextcloud/Talk/Deck upstream releases the platform depends on |
| `session-operating-map` | repo-local `docs/operating-map.md` lanes for multi-session work in the hubs repos |

Growth path: aiception + auto-capture generate candidates from real sessions; the 80%-overlap rule (update, don't fork) and the RECURRING/NON-OBVIOUS/CODIFIABLE bar filter them; approval flows through the directory card with `AGENT SKILL SUBSCRIBED/INSTALLED` receipts.

---

## 8. Training over time (the compounding loop)

Three layers, in adoption order:

1. **v1 (M5+): brain-in-the-loop queue work.** Runner preflight recalls from own brain + team brain before claiming; after `AGENT DONE`, capture one compact receipt thought ("Completed CARD-123: <what changed, what was verified, what to remember>", source `mcp`). This alone compounds: next similar card starts with prior context.
2. **M8a: ambient capture.** OB1 `auto-capture-claude-code` Stop hook (.mjs) on each laptop: ≥3 user turns, 25 s timeout, `import_key cc:<sid>:<sha8>` idempotency, retry queue + dead-letter. Adaptation required (known gap): the hook POSTs to `open-brain-rest/ingest`, which doesn't exist self-hosted — we add a minimal `/ingest` HTTP route to our mcp-server (auth: same `x-brain-key`; body `{content, source_type, import_key}`; dedupes on `metadata.import_key`; ~40 lines). This is the second and last piece of new code after capture-bot.
3. **M8b: governed agent memory.** `agent-memory` schema + API per personal brain (§2). Runners switch preflight to `POST /recall` (scoped, provenance-ranked, trace-logged) and postflight to `POST /writeback` (compact buckets: decisions/outputs/lessons/constraints/unresolved_questions/next_steps/failures/artifacts; the regex firewall 422-blocks secrets/large code/transcripts). Humans review pending memories weekly (`GET /memories/review` via a small CLI or psql view); `confirm` is the only path to instruction-grade. Weekly review is a 10-minute ritual per person, Friday, paired with OB1's Weekly Review companion prompt.

**Maintenance:** the Agent Maintenance Loop guide is adopted as-is — trigger-driven (upstream change / scope creep / rising human cost / quiet failure), never calendar-based. Each runner gets a replay pack (5–20 known-answer cards including one stop-and-escalate case, seeded from the M5 smoke-test cards kept in an `Archive: replay` Deck stack) and a 7-field maintenance record stored in `itsl-open-stack/runners/<code>/MAINTENANCE.md`. First scheduled trigger to watch: any Deck or Talk app upgrade on itsl.hubs.se (upstream change family).

---

## 9. Security / approval boundaries

**The boundary list, verbatim from the spec, in every runner prompt and every private context file:** never publish, email, Talk-post outside receipts/capture confirmations, deploy, delete, change billing, change credentials, or make outward-facing changes unless the card explicitly grants that approval. `AGENT HUMAN HOLD` for anything touching local permissions/accounts; `Agent Review` stack is the human gate for judgment-needing output.

ITSL-specific hard rules (added to boundaries, non-negotiable):
- **PII firewall:** Hubs production/dev case data (orosanmälningar, ärenderum, personnummer) never enters any brain, card, or Talk capture room. Authorized-display in the product is fine (core design principle); *copying into the agent substrate* is not — brains and the Deck board are not authorization-scoped surfaces. Capture rooms carry work notes, not case content.
- **Deploys:** `itsl` CLI against dev15 = allowed with card-level approval spelled out in the card body; against production = always human-run, agent prepares commands + a checklist only.
- **Git:** runners may branch/commit/push to feature branches in worktrees; merges to `main` and anything touching `hubs_*/appinfo` version bumps go through `Agent Review`.
- **Network posture:** brains and capture-bot are LAN/VPN + TLS; `brain.itsl.se` is not publicly routable in v1 (Caddy binds to the VPN interface). The only public-ish surface is the Talk webhook path, HMAC-verified.
- **Blast-radius:** capture-bot is the only component holding multiple DB credentials; it has INSERT+SELECT only (dedicated `capture` Postgres role per db, not the owner role). Runners hold no DB credentials at all — brains only via MCP keys.
- OB1 guard rails inherited: never modify core `thoughts` structure (add columns only); no secrets in files; no `DROP/TRUNCATE/unqualified DELETE` in any SQL we write.

---

## 10. Migration-to-production story

The stack is production-shaped from day one (that is a stated requirement): named volumes, pinned image tags, single `.env`, nightly `pg_dump` with 30-day rotation, healthchecks on every service (db: `pg_isready`; mcp: `tools/list` curl; capture-bot: `/health`).

Move = classic compose relocation, rehearsed once at M9 before it matters:
1. `docker compose down` on host A; final `pg_dump` all five dbs.
2. rsync `/opt/itsl-open-stack/` (compose, .env, backups) to host B (production adjacency: same segment as itsl.hubs.se, behind the same edge).
3. `docker compose up -d`; restore dumps if volumes weren't moved as-is; repoint `brain.itsl.se` DNS.
4. Re-run the full M2+M3 smoke battery (key-isolation matrix, Sarah capture test, tools/list ×5).
5. Nothing else changes: Deck/Talk were always on itsl.hubs.se; runners and laptops reference DNS names, not IPs.

Nothing in the stack depends on the host: no hostPath assumptions (we replaced the k8s hostPath with named volumes), no Supabase, no cloud services except metered Anthropic/OpenRouter APIs. If ITSL later wants k8s, the OB1 kubernetes-deployment manifests are the upstream we vendored from — the path back is documented by Nate himself. Optional later hardening: swap OpenRouter for a local embedding container (OpenAI-compatible `EMBEDDING_API_BASE` env swap, `vector(1536)` → model dimension migration) if data-residency requirements tighten.

---

## 11. Build order — milestones with verification

Each milestone has a smoke gate; do not proceed on red. Total: ~3 focused weeks for one builder (Fredrik + his Claude Code), with M5–M7 involving the team.

**M0 — Naming + repo + keys (½ day).** Create `itsl-open-stack` repo skeleton; make ALL naming decisions first (the spec's "most failed first runs come from mismatched names"): board `Agent Engine`, label `agent-instructions`, codes `reb/atlas/ada/marvin`, stack names, card title grammar, brain URLs. Fredrik creates all keys (§6 table) into the credential tracker + `.env`.
*Verify:* credential tracker complete; `docker compose config` parses; naming doc committed.

**M1 — First brain (atlas) (1 day).** Vendor OB1 k8s `index.ts`/`deno.json`/Dockerfile; write merged `init.sql` (§2); compose up `ob1-db-atlas` + `ob1-mcp-atlas`.
*Verify (OB1 canonical):* `curl -X POST http://localhost:8002 -H "x-brain-key: $ATLAS_KEY" -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'` → 6 tools; `capture_thought` the Sarah sentence from Claude Code → row visible in psql with embedding + metadata → `search_thoughts "What did I capture about Sarah?"` returns it with a `--- Result 1 (xx.x% match) ---` block; wrong key → 401.

**M2 — All five brains + proxy (1 day).** Scale to reb/ada/marvin/team; Caddy TLS on `brain.itsl.se`.
*Verify:* tools/list ×5 over HTTPS; **key-isolation matrix** — 5 keys × 5 endpoints, exactly the diagonal succeeds (scripted, kept as a permanent test: `tests/key-matrix.sh`); backup container produced 5 dumps overnight.

**M3 — Talk capture (2 days).** Build capture-bot; `occ talk:bot:install` + setup on 5 rooms.
*Verify:* Sarah test in `🧠 Atlas capture` → threaded confirmation + row with `source:"talk"`, `talk_id` set; **resend/dedupe test** (webhook retry simulated → still one row); message in an unconfigured room → ignored; tampered HMAC → 401 + no row; team-room message lands in team brain only.

**M4 — Deck board bootstrap (1 day).** Idempotent `engine/bootstrap-board.sh` (curl/OCS): creates board, 6 stacks in order, label, 4 standing cards with template bodies; `deck-cli.sh` complete.
*Verify:* re-running bootstrap creates nothing new (idempotent); `deck-cli.sh list-eligible atlas` returns empty cleanly; manual card create→move→comment→edit-comment round-trip via CLI works; each human posts their manual `AGENT STATUS` ledger comment.

**M5 — Interactive queue, one operator (2 days).** Install core skills for Fredrik; run queue checks by hand from Claude Code. Run the spec's four smoke tests **verbatim** as Deck cards:
*Verify:* (1) hello-world card `[agent instructions][atlas][task] Say hello from the queue` → `AGENT CLAIMED` → `AGENT DONE` → `Agent Done` stack → ledger `completed CARD-ID`; (2) blocked-resume card (intentionally missing date range) → `AGENT BLOCKED` + one specific question + `Agent Needs Input`; answer on card; next run → `AGENT UNBLOCKED`, `AGENT RESUMED`, `AGENT DONE`; (3) human-hold card → `AGENT HUMAN HOLD`, ledger `holding CARD-ID`, answer in Claude Code thread → `AGENT HUMAN ANSWERED` → completion; (4) optional-skill directory check → summary WITHOUT install. Runner stops after exactly one task.

**M6 — Headless runners (2 days).** Build `itsl/agent-runner`; deploy `runner-atlas` first, then all four; cron */30.
*Verify:* ledger heartbeats update **in place** (no comment pile-up) for 24 h; re-run all four M5 smoke cards driven purely by cron; **claim-lock test** — one eligible card, watch exactly one `AGENT CLAIMED`; kill a runner mid-task → next tick resumes sanely or leaves `AGENT FAILED` with last safe step + retry count; API spend per runner visible in Anthropic Console.

**M7 — Team path (1 week elapsed, low effort).** Onboard Rebecca, Sandra, Mattias **one at a time** (spec rule): install context, ledger comment, one tiny smoke card assigned to them; fill routing map ownership areas.
*Verify per person:* their hello-world card completes end-to-end via their runner. **Cross-agent routing test:** Fredrik creates `[agent instructions][ada][task] ...` assigned to `sandra` → ada claims and completes; a deliberately mis-assigned card (ada task assigned to fredrik) is NOT picked up — proving the eligibility filter.

**M8 — Training loop (3 days).** a) `/ingest` route + Stop hook on all laptops; b) agent-memory schema + API per personal brain; runners switch to recall/writeback; weekly review ritual starts.
*Verify:* a) a ≥3-turn Claude Code session produces exactly one ambient thought (`source:"claude_code_ambient"`); duplicate Stop events dedupe on import_key. b) adapted OB1 `live-smoke.mjs` passes against one brain: writeback lands pending/evidence-only with trust defaults intact, conservative recall excludes it, `include_unconfirmed` returns it, unsafe writeback (seeded fake `sk-or-v1-...` key) → **422 blocked** — the seeded-failure negative test, per the build-guides doctrine that guards need both a passing and a failing artifact.

**M9 — Production hardening (2 days).** Restore rehearsal (fresh volumes ← last night's dumps → key-matrix + Sarah tests green); move rehearsal to the target prod host (§10); healthcheck alerting into a Talk ops room; replay packs + MAINTENANCE.md seeded for all four runners; write `docs/operating-map.md` for the stack repo.
*Verify:* restore-from-backup drill passes the full smoke battery; a simulated dead brain (stop `ob1-mcp-ada`) alerts within 5 minutes; the ledger shows `Automation state: blocked` semantics used correctly during the drill.

---

## 12. Risks

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| 1 | **Weaker claim lock than Linear.** Deck has no atomic status transition; two runners could theoretically race. | Medium | Nate's own mitigations carry over: per-agent title-bracket scoping means only ONE runtime is ever eligible per card (races only matter intra-agent, and each agent has one runner + flock); claim = move + `AGENT CLAIMED` + mandatory re-read. M6 claim-lock smoke test proves it. |
| 2 | **Ledger comment editing.** The in-place `AGENT STATUS` update needs Deck's comment-update API (OCS comments PUT, author-only). If a Deck version quirk blocks edits, the ledger degrades into heartbeat clutter — a named failure mode in the spec. | Medium | M4 explicitly tests edit-comment round-trip before anything depends on it. Fallback (still faithful): delete-and-repost own comment atomically in `deck-cli.sh`. |
| 3 | **Deck board is team-visible; Linear private issues aren't replicated.** Private setup content can't live on the board. | Low | Spec already allows "private issue **or local runtime context**" — we use local files (§3.6). Verified by policy review at M5. |
| 4 | **Headless runner cost drift.** Cron ×4 ×48/day with a capable model. | Medium | One-task-per-run cap + `--max-turns 40` + `none` runs are cheap (no eligible card → ledger update only). Per-runner API keys make spend attributable; review at M6+2 weeks; drop tick rate to hourly if idle cost dominates. |
| 5 | **OpenRouter = thought content leaves premises for embedding/extraction.** | Medium (policy) | Capture rooms are work-notes-only, PII firewall in every boundary list. If residency tightens: local OpenAI-compatible embedder swap is a documented env change (§10) — but do it later; fidelity says run the proven pipeline first. |
| 6 | **Talk bot framework quirks** (webhook delivery retries, bot API rate limits, no `message.groups`-style dual-event trap but its own event-type filtering). | Medium | capture-bot filters on explicit room-token allowlist + activity type; dedupe on `talk_id` absorbs retries (M3 tests). The Slack function's failure-mode vocabulary maps 1:1 and is in the runbook. |
| 7 | **OB1 known defects ride along** (fetch/updated_at bug, README/code drift, `list_thoughts` days interpolation). | Low | Neutralized by choosing the production UUID schema (§2); days param is zod-typed numeric; we track upstream OB1 for fixes since we vendored, not forked. |
| 8 | **Skill routing silently broken by multi-line descriptions** (documented OB1 caveat). | Low | Repo CI lint: frontmatter description must be single-line ≤1024 chars; checked on every skills/ PR. |
| 9 | **Team adoption stalls** — queue ceremony without habit; "a queue without receipts is a prettier inbox." | High (human) | The spec's own answer: onboard one person at a time with a working smoke test each (M7); routing map makes ownership legible; Fredrik runs the engine for himself for a full week (M5–M6) before anyone else touches it. |
| 10 | **Agent-memory API port complexity** (Supabase→Postgres swap in M8b) exceeds estimate. | Medium | It's isolated to M8b; v1 through M8a compounds fine on `capture_thought` + auto-capture alone. Ship M8b only when the weekly-review habit exists to consume it. |
| 11 | **Anthropic/Claude Code CLI changes** breaking headless flags. | Low | Pin CLI version in the runner image; upgrade = an Agent Maintenance Loop "upstream change" trigger with replay-pack verification. |

---

## Appendix A — Verbatim artifacts carried over unchanged

To make the fidelity claim auditable, these ship byte-identical (modulo Linear→Deck word substitution) from the sources:

1. The six status names and their semantics (Open Engine §Step 2).
2. The `agent-instructions` label spelling.
3. The full receipt vocabulary, all 16 tokens (§Receipts) — table in §3.4.
4. The `AGENT STATUS` ledger comment format, all 11 fields, and the `Last queue result` value vocabulary.
5. The queue-run prompt `<task>/<order>/<receipts>/<boundaries>` (all 20 order steps).
6. The private context file template and its 20 rules.
7. The task card body template (requester/outcome/context/sources/do/acceptance/output/boundaries).
8. The routing map shape.
9. The four smoke tests (hello-world, blocked-resume, human-hold, directory check) and their acceptance criteria.
10. OB1 production schema SQL (thoughts, match_thoughts, upsert_thought, fingerprint dedupe).
11. OB1 k8s mcp-server `index.ts` (6 tools, metadata prompt, auth, stateless transport) with the single documented capture→upsert change.
12. The Slack `ingest-thought` behavioral contract (filter→dedupe→parallel embed/extract→insert→threaded confirm) as the capture-bot spec.
13. The Sarah smoke-test sentence and expected reply.
14. auto-capture, aiception, and the SKILL.md conventions (single-line description caveat included).
15. The Agent Maintenance Loop (7 surfaces, 6 steps, replay pack, keep/change/pause/retire, 7-field record).
