# OB1 Agent Memory — Exhaustive Digest

Digest of Nate B. Jones's OB1 "Agent Memory" subsystem: the governed mechanism by which agent runtimes (OpenClaw first; also Codex, Claude Code, local agents, n8n) train over time — recalling scoped, provenance-labeled memories before work and writing back compact operational memory after work, with human review gating, use-policy enforcement, recall traces, and full audit.

Sources digested (all under `natebjones/OB1/` in the scratchpad):
- `schemas/agent-memory/` (README.md, schema.sql, metadata.json)
- `integrations/agent-memory-api/` (README.md, index.ts, deno.json, metadata.json, smoke/live-smoke.mjs, smoke/cleanup-test-memory.mjs, smoke/seed-nate-continuity-demo.mjs)
- `recipes/openclaw-agent-memory/` (README.md, contracts/*.schema.json, examples/*.json, metadata.json)
- `recipes/openclaw-code-review-memory/` (README.md, examples/recall.json, examples/writeback.json, metadata.json)
- `recipes/openclaw-taskflow-work-log/` (README.md, examples/recall.json, examples/writeback.json, metadata.json)
- `docs/agent-memory-portability.md`

Referenced but NOT present in the digested tree (see OPEN QUESTIONS): `docs/safe-agent-memory-provenance.md`, `docs/01-getting-started.md`, `docs/05-tool-audit.md`, `docs/assets/agent-memory/`, `integrations/openclaw-agent-memory/plugin/`, `skills/openclaw-agent-memory/`, the dashboard app, and the core `thoughts` table / `match_thoughts` / `upsert_thought` definitions.

---

## 1. Concept and trust model

- OB1 (Open Brain) has a core content table `public.thoughts` (with embeddings; searched via a `match_thoughts` RPC and written via an `upsert_thought` RPC — both from the core Open Brain setup, not defined here).
- Agent Memory is a **sidecar schema**: `thoughts` remains the durable content store; agent-memory records add provenance, confidence, scope, use policy, review status, source references, recall traces, and audit events. Existing OB1 capture/search behavior keeps working.
- Runtime-neutral by design: "OpenClaw is the first launch runtime, but these endpoints are runtime-neutral and can be used by Codex, Claude Code, local agents, n8n, or future SQLite adapters."
- Core governance loop (the "trains over time with governance" mechanism):
  1. Agent runtime issues **Recall request** → OB1 returns **scoped memories with provenance + use policy**.
  2. Runtime does the work.
  3. Runtime posts **compact write-back** (never raw transcripts).
  4. Write-back enters **human review** (pending) — evidence-only until reviewed.
  5. Reviewed/confirmed memory feeds **future recall**.
- Evidence-vs-instruction is the central distinction:
  - `can_use_as_evidence` (default `true`): memory may inform/inspire.
  - `can_use_as_instruction` (default `false`): memory may direct agent behavior. Only allowed when `provenance_status IN ('user_confirmed','imported')` — enforced by a DB CHECK constraint.
  - `requires_user_confirmation` (default `true`): pending memories are gated out of conservative recall.
- Stated defaults (recipe README, verbatim list):
  - "Agent-written memory starts as evidence, not instruction."
  - "Instruction-grade memory requires human confirmation or trusted import."
  - "Write-back requires idempotency and content-hash dedupe."
  - "Raw transcripts, model reasoning traces, secrets, large code blocks, and private customer dumps are blocked or flagged."
  - "Project scope is the default when available."
  - "Personal or channel memory never auto-promotes to team or workspace scope."

---

## 2. Database schema (`schemas/agent-memory/schema.sql`)

Postgres/Supabase migration, wrapped in `BEGIN; ... COMMIT;`. Precondition guard: a `DO $$` block raises `'agent-memory requires public.thoughts. Run docs/01-getting-started.md first.'` if `public.thoughts` does not exist.

### 2.1 `public.agent_memories` (main record)

Columns (exact):
- `id UUID PK DEFAULT gen_random_uuid()`
- `thought_id UUID REFERENCES public.thoughts(id) ON DELETE SET NULL` — link to core content row
- `workspace_id TEXT NOT NULL`
- `project_id TEXT`
- `channel_kind TEXT`, `channel_id TEXT`, `channel_thread_id TEXT`
- `visibility TEXT NOT NULL DEFAULT 'project'` CHECK in `('personal','channel','project','workspace','organization')`
- `memory_type TEXT NOT NULL` CHECK in `('decision','output','lesson','constraint','open_question','failure','artifact_reference','work_log')`
- `summary TEXT NOT NULL`, `content TEXT NOT NULL`
- `lifecycle_status TEXT NOT NULL DEFAULT 'active'` CHECK in `('active','stale','superseded','disputed','rejected')`
- `provenance_status TEXT NOT NULL DEFAULT 'generated'` CHECK in `('observed','inferred','user_confirmed','imported','generated','superseded','disputed')`
- `confidence NUMERIC(3,2) NOT NULL DEFAULT 0.50` CHECK 0..1
- `created_by TEXT NOT NULL DEFAULT 'agent'` CHECK in `('user','agent','system','import')`
- `runtime_name TEXT`, `runtime_version TEXT`, `provider TEXT`, `model TEXT`
- `task_id TEXT`, `flow_id TEXT`
- `can_use_as_instruction BOOLEAN NOT NULL DEFAULT false`
- `can_use_as_evidence BOOLEAN NOT NULL DEFAULT true`
- `requires_user_confirmation BOOLEAN NOT NULL DEFAULT true`
- `review_status TEXT NOT NULL DEFAULT 'pending'` CHECK in `('pending','confirmed','evidence_only','restricted','rejected','stale','merged')`
- `last_confirmed_at TIMESTAMPTZ`, `stale_after TIMESTAMPTZ`
- `idempotency_key TEXT`, `content_hash TEXT`
- `metadata JSONB NOT NULL DEFAULT '{}'::jsonb`
- `created_at`/`updated_at TIMESTAMPTZ NOT NULL DEFAULT now()`
- **Table-level trust CHECK (load-bearing):** `CHECK (can_use_as_instruction = false OR provenance_status IN ('user_confirmed','imported'))`

Indexes:
- `idx_agent_memories_idempotency_key` UNIQUE on `(idempotency_key)` WHERE `idempotency_key IS NOT NULL`
- `idx_agent_memories_scope` on `(workspace_id, project_id, visibility)`
- `idx_agent_memories_review` on `(review_status, lifecycle_status, created_at DESC)`
- `idx_agent_memories_runtime_task` on `(runtime_name, task_id, flow_id)`
- `idx_agent_memories_content_hash` on `(workspace_id, content_hash)` WHERE `content_hash IS NOT NULL`

### 2.2 `public.agent_memory_source_refs` (provenance evidence)
`id UUID PK`, `memory_id UUID NOT NULL FK agent_memories ON DELETE CASCADE`, `source_kind TEXT NOT NULL`, `uri TEXT`, `title TEXT`, `source_timestamp TIMESTAMPTZ`, `metadata JSONB DEFAULT '{}'`, `created_at`. Index `idx_agent_memory_source_refs_memory (memory_id)`.

### 2.3 `public.agent_memory_artifacts`
`id UUID PK`, `memory_id FK CASCADE`, `artifact_kind TEXT NOT NULL`, `uri TEXT NOT NULL`, `description TEXT`, `metadata JSONB`, `created_at`. Index on `(memory_id)`.

### 2.4 `public.agent_memory_relations`
`id UUID PK`, `from_memory_id`/`to_memory_id` both FK CASCADE, `relation TEXT NOT NULL` CHECK in `('related_to','supersedes','superseded_by','conflicts_with','merged_into')`, `confidence NUMERIC(3,2) DEFAULT 0.50` (nullable, 0..1), `metadata JSONB`, `created_at`; `UNIQUE (from_memory_id, to_memory_id, relation)`; `CHECK (from_memory_id <> to_memory_id)`.

### 2.5 `public.agent_memory_review_actions` (human review log)
`id UUID PK`, `memory_id FK CASCADE`, `action TEXT NOT NULL` CHECK in `('confirm','edit','evidence_only','restrict_scope','mark_stale','merge','reject','dispute','supersede')`, `actor_id TEXT`, `actor_label TEXT`, `notes TEXT`, `before JSONB`, `after JSONB` (full row snapshots), `created_at`. Index `(memory_id, created_at DESC)`.

### 2.6 `public.agent_memory_recall_traces` (one row per recall request)
`id UUID PK`, `request_id UUID NOT NULL DEFAULT gen_random_uuid()` UNIQUE, `workspace_id TEXT NOT NULL`, `project_id`, `runtime_name`, `runtime_version`, `task_id`, `flow_id`, `channel_kind`, `channel_id`, `query TEXT NOT NULL`, `schema_version TEXT NOT NULL`, `request_payload JSONB DEFAULT '{}'`, `response_policy JSONB DEFAULT '{}'`, `created_at`. Index `(workspace_id, project_id, created_at DESC)`.

### 2.7 `public.agent_memory_recall_items` (per returned memory per trace)
`id UUID PK`, `trace_id FK recall_traces CASCADE`, `memory_id FK agent_memories CASCADE`, `rank INTEGER NOT NULL`, `similarity NUMERIC(5,4)`, `ranking_score NUMERIC(7,4)`, `returned BOOLEAN NOT NULL DEFAULT true`, `used BOOLEAN` (null until usage report), `ignored_reason TEXT`, `use_policy_snapshot JSONB DEFAULT '{}'` (policy at recall time), `created_at`; `UNIQUE (trace_id, memory_id)`. Index `(trace_id, rank)`.

### 2.8 `public.agent_memory_audit_events`
`id UUID PK`, `event_type TEXT NOT NULL` CHECK in `('recall_requested','memory_returned','memory_used','memory_ignored','memory_written','memory_confirmed','memory_edited','memory_rejected','memory_superseded','memory_disputed')`, `workspace_id`, `project_id`, `memory_id FK SET NULL`, `trace_id FK SET NULL`, `actor_kind TEXT NOT NULL DEFAULT 'system'` CHECK in `('user','agent','system','import')`, `actor_label TEXT`, `runtime_name TEXT`, `task_id TEXT`, `payload JSONB DEFAULT '{}'`, `created_at`. Index `(workspace_id, project_id, created_at DESC)`.

### 2.9 Functions, trigger, RLS, grants
- `public.agent_memories_set_updated_at()` plpgsql trigger fn; trigger `trg_agent_memories_updated_at` BEFORE UPDATE ON `agent_memories`.
- `public.agent_memory_hash_text(p_content TEXT) RETURNS TEXT IMMUTABLE` — normalization hash: `encode(sha256(convert_to(lower(trim(regexp_replace(coalesce(p_content,''), '\s+', ' ', 'g'))), 'UTF8')), 'hex')` (lowercase, whitespace-collapsed SHA-256 hex).
- RLS ENABLED on all 8 tables; each gets a policy named `<table>_service_role_all` — `FOR ALL TO service_role USING (true) WITH CHECK (true)` (service-role-only access; the Edge Function uses the service role key).
- `GRANT SELECT, INSERT, UPDATE, DELETE` on all 8 tables to `service_role`; `GRANT EXECUTE ON FUNCTION public.agent_memory_hash_text(TEXT) TO service_role`.
- Ends with `NOTIFY pgrst, 'reload schema';` so PostgREST reloads.

### 2.10 Install verification (schema README)
- Step 1 done when Table Editor shows `agent_memories`, `agent_memory_recall_traces`, `agent_memory_recall_items`, `agent_memory_audit_events`.
- Step 2 verification SQL (verbatim):
  ```sql
  SELECT column_name, column_default
  FROM information_schema.columns
  WHERE table_name = 'agent_memories'
    AND column_name IN (
      'can_use_as_instruction',
      'can_use_as_evidence',
      'requires_user_confirmation',
      'review_status'
    );
  ```
  Done when: instruction defaults `false`, evidence defaults `true`, confirmation defaults `true`, review defaults `pending`.
- Step 3: deploy the API; done when `GET /health` returns `{"ok":true}`.
- Troubleshooting: "instruction-grade write fails … This is usually correct" (the CHECK constraint working as intended). "API cannot read tables" → re-run GRANT section + redeploy Edge Function.
- Metadata: category `schemas`, version `0.1.0`, difficulty `advanced`, estimated_time `20 minutes`, created/updated `2026-05-03`.

---

## 3. Agent Memory API (`integrations/agent-memory-api/index.ts`)

Supabase Edge Function (Deno), Hono router, Zod validation, `@supabase/supabase-js` with SERVICE ROLE key. Version string in `/health`: `{ ok: true, service: "agent-memory-api", version: "0.1.0" }`.

Dependencies (`deno.json` imports, exact pins): `@supabase/supabase-js` npm 2.47.10, `hono` npm 4.9.2, `zod` npm 4.1.13.

Env/secrets: `SUPABASE_URL`, `SUPABASE_SERVICE_ROLE_KEY`, `OPENROUTER_API_KEY`, `MCP_ACCESS_KEY`. Embeddings via OpenRouter (`https://openrouter.ai/api/v1/embeddings`), model `openai/text-embedding-3-small`.

Auth: every route (via `app.use("*")`) requires `x-brain-key` header OR `?key=` query param equal to `MCP_ACCESS_KEY`; otherwise 401 `{"error":"Invalid or missing access key"}`. CORS: `Access-Control-Allow-Origin: *`, allowed headers `authorization, x-client-info, apikey, content-type, x-brain-key`, methods `GET, POST, PATCH, OPTIONS`.

Path mounting: `Deno.serve` strips a leading `/agent-memory-api` prefix before dispatching to Hono (so both `/agent-memory-api/health` and `/health` work behind the Supabase functions URL).

Deploy commands (verbatim):
```bash
supabase functions new agent-memory-api
cp integrations/agent-memory-api/index.ts supabase/functions/agent-memory-api/index.ts
cp integrations/agent-memory-api/deno.json supabase/functions/agent-memory-api/deno.json
supabase functions deploy agent-memory-api --no-verify-jwt
```
Done when `supabase functions list` shows `agent-memory-api` active. Health test:
`curl "https://YOUR_PROJECT_REF.supabase.co/functions/v1/agent-memory-api/health?key=YOUR_MCP_ACCESS_KEY"`.

### 3.1 Endpoint table (verbatim purposes)

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `/health` | GET | Verify deployment |
| `/recall` | POST | Retrieve scoped memories before work starts |
| `/writeback` | POST | Save compact operational memory after work finishes |
| `/recall/:request_id/usage` | POST | Report which recalled memories were used or ignored |
| `/memories` | GET | List memories by workspace, project, status, runtime, type, or task prefix |
| `/memories/review` | GET | List pending agent-written memories |
| `/memories/:id` | GET | Inspect one memory with source/artifact details |
| `/memories/:id/review` | PATCH | Confirm, edit, reject, restrict, stale, dispute, or supersede |
| `/recall-traces/:request_id` | GET | Debug what was recalled and how it was used |

### 3.2 Schema-version tokens (accepted by the API)

| Contract | Runtime-Neutral | OpenClaw Alias |
| -------- | --------------- | -------------- |
| Recall request | `openbrain.agent_memory.recall.v1` | `openbrain.openclaw.recall.v1` |
| Recall response | `openbrain.agent_memory.recall_response.v1` | `openbrain.openclaw.recall_response.v1` |
| Write-back request | `openbrain.agent_memory.writeback.v1` | `openbrain.openclaw.writeback.v1` |
| Write-back response | `openbrain.agent_memory.writeback_response.v1` | `openbrain.openclaw.writeback_response.v1` |

Response schema_version mirrors the request family (openclaw request → openclaw response alias).

### 3.3 Zod request shapes (API-side; slightly looser than the JSON Schema contracts)

**Recall request** (`recallSchema`):
- `schema_version` (one of the two recall tokens), `workspace_id` (min 1), `project_id?`, `task_id?`, `flow_id?`, `task_type?`
- `channel { kind?, id?, thread_id? }` default `{}`
- `runtime { name default "unknown", version? }`
- `model_intent { provider?, model? }` default `{}`
- `query` (min 1)
- `entities`: record of string → string[] , default `{}`
- `scope { visibility?, project_only default true, include_unconfirmed default false, include_stale default false }`
- `limits { max_items int 1..50 default 10, max_tokens int 256..20000 default 4000, recency_days? positive int }`
- `sensitivity`: record string → boolean, default `{}`

**Write-back request** (`writebackSchema`):
- `schema_version` (writeback token), `workspace_id`, `project_id?`, `task_id?`, `flow_id?`, `step_id?`, `idempotency_key?`, `content_hash?`
- `channel`, `runtime` as above
- `models_used[] { provider, model, role }` default `[]`
- `source_refs[] { kind, uri?, title?, timestamp? }` default `[]`
- `memory_payload` (`memoryPayloadSchema`): `decisions[]`, `outputs[]`, `lessons[]`, `constraints[]`, `unresolved_questions[]`, `next_steps[]`, `failures[]` (all string arrays default `[]`), `artifacts[] { kind, uri, description? }`, `entities` record string → string[]
- `provenance { default_status enum ['observed','inferred','user_confirmed','imported','generated'] default 'generated', confidence 0..1 default 0.5, requires_review boolean default true }`
- `retention { ttl_days? positive int, stale_after_days? positive int }` default `{}`
- `visibility { workspace?, project?, channel? }` default `{}`

**Usage report** (`usageSchema`): `used_memory_ids: string[]` default `[]`; `ignored: [{ memory_id, reason? }]` default `[]`.

**Review** (`reviewSchema`): `action` enum `['confirm','edit','evidence_only','restrict_scope','mark_stale','merge','reject','dispute','supersede']`; `actor_id?`, `actor_label?`, `notes?`, `content?`, `summary?`, `visibility?`, `related_memory_id?`.

### 3.4 POST /recall — retrieval, gating, ranking, tracing

1. Embed `query` via OpenRouter (`openai/text-embedding-3-small`).
2. Call core RPC `match_thoughts(query_embedding, match_threshold: 0.25, match_count: max(max_items*4, 20), filter: {})` → similarity per thought id.
3. Load up to 100 most recent `agent_memories` rows for `workspace_id`; if there are matched thought ids, filter `thought_id IN (...)`.
4. **Scope/policy gate** (`scopeMatches`, exact logic):
   - workspace mismatch → excluded
   - `scope.project_only && project_id` set and memory.project_id differs → excluded
   - not `include_stale` and `lifecycle_status IN ('stale','superseded','rejected','disputed')` → excluded
   - not `include_unconfirmed` and memory `requires_user_confirmation` and `review_status === 'pending'` → excluded (this is the review gate)
   - memory `visibility === 'personal'` and requested `scope.visibility !== 'personal'` → excluded (personal never leaks upward)
5. **Ranking** (`rankMemory` — governance-weighted score, exact weights):
   - provenance bonus: `user_confirmed` +0.3, `imported` +0.22, `observed` +0.15, `generated` +0.05, else 0
   - policy bonus: `can_use_as_instruction` +0.2, else `can_use_as_evidence` +0.08, else −0.2
   - review bonus: `confirmed` +0.15, `evidence_only` +0.05, `pending` −0.08, else −0.25
   - final: `similarity + provenance + policy + review + confidence * 0.15`
   - sort desc, take `limits.max_items`.
6. Insert one `agent_memory_recall_traces` row (stores full `request_payload`, `response_policy: { max_items, include_unconfirmed }`, schema_version, runtime/channel/task identity). Insert `agent_memory_recall_items` rows (rank starting at 1, similarity, ranking_score, and `use_policy_snapshot` = the three policy booleans at recall time).
7. Audit event `recall_requested` with `returned_count`.
8. Response: `{ schema_version: <response token>, request_id: trace.request_id, memories: [responseMemory...] }`.

**Response memory shape** (`responseMemory`, keys exact): `memory_id`, `summary`, `content`, `source { kind: "agent_memory", uri: null, title: summary, timestamp: created_at }`, `provenance { status, confidence, created_by, model, runtime }`, `scope { workspace_id, project_id, channel_id, visibility }`, `use_policy { can_use_as_instruction, can_use_as_evidence, requires_user_confirmation }`, `freshness { created_at, last_confirmed_at, stale_after }`, `related_artifacts: []` (always empty array in v1 API code).

### 3.5 POST /writeback — safety, dedupe, storage

1. Validate; expand `memory_payload` into rows (`memoryRows`, exact mapping):
   - `decisions[]` → memory_type `decision`
   - `outputs[]` → `output`
   - `lessons[]` → `lesson`
   - `constraints[]` → `constraint`
   - `unresolved_questions[]` → `open_question`
   - `next_steps[]` → `work_log` with content prefixed `Next step: <content>`
   - `failures[]` → `failure`
   - `artifacts[]` → `artifact_reference` with content `` `${kind}: ${description || uri}\n${uri}` ``
   - 0 rows → 400 `"memory_payload produced no memory rows"`.
2. **Unsafe-content firewall** (`unsafeReasons`, regexes exact; reason tokens exact):
   - `private_key`: `/-----BEGIN (?:RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----/`
   - `api_key`: `/(?:sk-[A-Za-z0-9_-]{20,}|sk-or-v1-[A-Za-z0-9_-]{20,})/`
   - `credential_like_string`: `/(?:password|passwd|secret|token)\s*[:=]\s*\S{12,}/i`
   - `large_code_block`: ≥4 occurrences of ``` ``` `` OR >20 lines longer than 120 chars
   - `raw_transcript_like`: text length >15000 OR >8 lines matching `/^(user|assistant|system|agent|human):/i`
   - Any hit → audit `memory_rejected` (actor_kind `system`, reason `unsafe_writeback`, per-row reasons) and HTTP **422** `{ error: "Unsafe write-back blocked", unsafe: [...] }`. Nothing is stored.
3. `defaultInstruction = provenance.default_status IN ('user_confirmed','imported') AND !provenance.requires_review` — the ONLY path to instruction-grade at write time (trusted import).
4. Per row:
   - `content_hash = sha256Hex("<memory_type>:<content>")`
   - `idempotency_key = (req.idempotency_key || "<workspace_id>:<runtime.name>:<task_id||'taskless'>:<step_id||'step'>:<content_hash>") + ":" + index`
   - If a row with that idempotency_key exists → return existing (dedupe, no double write).
   - Embed content; call core RPC `upsert_thought(p_content, p_payload)` with metadata `{ source: "agent_memory", source_type: "agent_memory", type: <memory_type>, topics: entities.topics||[], people: entities.people||[], agent_memory: { runtime, task_id, flow_id, provenance_status } }`; then `UPDATE thoughts SET embedding = ...`.
   - Insert `agent_memories` row: `visibility = project_id ? 'project' : 'personal'`; `summary = content whitespace-collapsed .slice(0,140)`; `created_by = default_status==='imported' ? 'import' : 'agent'`; `can_use_as_instruction = defaultInstruction`; `can_use_as_evidence = true`; `requires_user_confirmation = !defaultInstruction`; `review_status = defaultInstruction ? 'confirmed' : 'pending'`; `last_confirmed_at` set only if defaultInstruction; `stale_after = now + retention.stale_after_days` (if provided); `metadata = { source_refs, models_used, retention, writeback_schema_version }`; provider/model taken from `models_used[0]`.
   - Insert `agent_memory_source_refs` rows for each source_ref.
   - For `artifact_reference` rows: insert `agent_memory_artifacts` for every payload artifact.
   - Audit `memory_written` (actor_kind `agent`, includes provenance_status and review_status).
5. Response: `{ schema_version: <writeback response token>, memories: [responseMemory...] }`.

### 3.6 POST /recall/:request_id/usage — the learning-feedback signal
- Looks up trace by `request_id` (404 if missing).
- For each `used_memory_ids`: sets `agent_memory_recall_items.used = true`; audit `memory_used`.
- For each `ignored[]`: sets `used = false`, `ignored_reason`; audit `memory_ignored`.
- Returns `{ ok: true }`.

### 3.7 GET /memories and GET /memories/review (inspection)
- `/memories/review`: requires `workspace_id` query param; optional `project_id`; returns up to 100 rows with `review_status = 'pending'` newest first (the human review queue).
- `/memories`: requires `workspace_id`; `limit` clamped 1..200 default 50; optional exact-match filters `project_id`, `review_status`, `lifecycle_status`, `runtime_name`, `memory_type`; prefix filter `task_id_prefix` (`LIKE '<prefix>%'`). Returns `{ memories, count }`.
- `/memories/:id`: single row with joined `agent_memory_source_refs(*)` and `agent_memory_artifacts(*)`.

### 3.8 PATCH /memories/:id/review — human governance actions (exact state transitions)

Reads the `before` row, applies updates, writes `after`, logs both snapshots to `agent_memory_review_actions`, emits an audit event.

| action | updates applied |
| --- | --- |
| `confirm` | review_status=`confirmed`, provenance_status=`user_confirmed`, can_use_as_instruction=`true`, requires_user_confirmation=`false`, last_confirmed_at=now |
| `evidence_only` | review_status=`evidence_only`, can_use_as_instruction=`false`, can_use_as_evidence=`true`, requires_user_confirmation=`false` |
| `reject` | review_status=`rejected`, lifecycle_status=`rejected`, can_use_as_instruction=`false`, can_use_as_evidence=`false` |
| `mark_stale` | review_status=`stale`, lifecycle_status=`stale`, can_use_as_instruction=`false` |
| `dispute` | lifecycle_status=`disputed`, provenance_status=`disputed`, can_use_as_instruction=`false` |
| `restrict_scope` | review_status=`restricted`, visibility=`req.visibility || 'personal'` |
| `edit` | content and/or summary replaced if provided |
| `merge` / `supersede` | no direct field updates in the update map; if `related_memory_id` present, inserts `agent_memory_relations` row with relation `merged_into` (merge) or `supersedes` (supersede), confidence 1 |

Audit event mapping: confirm→`memory_confirmed`, edit→`memory_edited`, reject→`memory_rejected`, supersede→`memory_superseded`, dispute→`memory_disputed`; any other action falls back to `memory_edited`. Audit actor_kind is `user` for review actions.

### 3.9 GET /recall-traces/:request_id
Returns `{ trace, items }` where items are `agent_memory_recall_items` ordered by rank with the joined `agent_memories(*)` row — full "what was recalled, how it ranked, whether it was used/ignored and why" debugging surface.

### 3.10 API metadata
Category `integrations`, version `0.1.0`, requires services `["OpenRouter"]`, tools `["Supabase CLI"]`, difficulty `advanced`, estimated_time `30 minutes`. Tool-surface note: plugins can wrap this API as tools; consult `docs/05-tool-audit.md` before adding runtime-specific tool surfaces.

Troubleshooting (README, verbatim gist): missing key → include `?key=...` or `x-brain-key`; recall empty → confirm write-back created rows and they are confirmed or `include_unconfirmed` is true; write-back blocked → "Store a compact summary and artifact links. Do not submit raw transcripts, reasoning traces, secrets, or large code blocks."

---

## 4. Smoke, cleanup, and seed harnesses (`integrations/agent-memory-api/smoke/`)

### 4.1 `live-smoke.mjs` — release-checklist verification
Env: `OB1_AGENT_MEMORY_ENDPOINT` (required), `OB1_AGENT_MEMORY_KEY` or `MCP_ACCESS_KEY`, `OB1_AGENT_MEMORY_WORKSPACE_ID` (default `ob1-staging`), `OB1_AGENT_MEMORY_PROJECT_ID` (default `agent-memory-api-smoke`), `OB1_AGENT_MEMORY_RUN_ID` (default timestamp). Task id: `agent-memory-api-smoke-<runId>`. Auth via `x-brain-key` header. Prints a JSON summary; **never prints the access key**.

Checks, in order (each asserted):
1. `health` → `ok === true`.
2. `writeback` (schema `openbrain.openclaw.writeback.v1`, provenance `{ default_status: "generated", confidence: 0.83, requires_review: true }`, retention `stale_after_days: 30`) → ≥3 memories written; ALL have `can_use_as_instruction === false`, `can_use_as_evidence === true`, `requires_user_confirmation === true` (asserts the trust defaults survived).
3. `memory_list`: `/memories?workspace_id&project_id&task_id_prefix&limit=20` returns the new rows.
4. `conservative_recall_gate`: `/recall` with `include_unconfirmed: false` must NOT return any newly written (pending, generated) memory.
5. `include_unconfirmed_recall`: `/recall` with `include_unconfirmed: true` must return at least one of them.
6. `usage_reporting`: POST usage with one used id + up to 2 ignored (reason "not needed for live API smoke assertion").
7. `review_action`: PATCH review `action: "evidence_only"` (actor_label "OB1 Agent Memory API live smoke") → `review_status === 'evidence_only'` and `can_use_as_instruction === false`.
8. `inspect_memory`: GET `/memories/:id` returns the right row.
9. `recall_trace`: GET `/recall-traces/:request_id` → the used item has `used === true`.
10. `unsafe_writeback_block`: a payload containing `api_key: sk-or-v1-0000...` expects HTTP 422 with `error === "Unsafe write-back blocked"`.

Run command (README, verbatim envs):
```bash
OB1_AGENT_MEMORY_ENDPOINT="https://YOUR_PROJECT_REF.supabase.co/functions/v1/agent-memory-api" \
OB1_AGENT_MEMORY_KEY="YOUR_MCP_ACCESS_KEY" \
OB1_AGENT_MEMORY_WORKSPACE_ID="ob1-staging" \
OB1_AGENT_MEMORY_PROJECT_ID="agent-memory-api-smoke" \
node integrations/agent-memory-api/smoke/live-smoke.mjs
```

### 4.2 `cleanup-test-memory.mjs` — non-destructive test hygiene
- Env `OB1_AGENT_MEMORY_TEST_PROJECT_IDS` default `"agent-memory-api-smoke,agent-memory-openclaw-smoke"`.
- Guard: refuses any project id not matching `/(^|[-_])(smoke|test|testing|sandbox)([-_]|$)/i` ("Refusing to clean non-test project id").
- Default dry-run; `--apply` PATCHes each active memory with review `action: "reject"` (actor_label "OB1 Agent Memory test cleanup harness", note "Rejected smoke/test memory so it cannot influence personal recall."). **Never deletes rows** — rejection removes them from recall while preserving audit.

### 4.3 `seed-nate-continuity-demo.mjs` — demo/starter-knowledge seeding
Defaults: workspace `nate-jones-personal-ob1`, project `continuity-os`, run id `nate-continuity-v1`, dashboard base `http://localhost:3020`. Seeds six batches then performs a recall + usage report and prints dashboard URLs (`/agent-memory?workspace_id&project_id&review_status=...` and `/agent-memory/traces?request_id=...`). Demonstrates every governance state:

| batch slug | provenance default_status / confidence / requires_review | post-write review action | stale_after_days | content style |
| --- | --- | --- | --- | --- |
| `core-operating-rules` | imported / 0.97 / **false** (→ instruction-grade + confirmed on write) | none | 180 | decisions prefixed `Rule:` |
| `public-reference-pack` | imported / 0.91 / true | `evidence_only` | 45 | lessons prefixed `Reference:` |
| `ob1-repo-map` | imported / 0.94 / true | `evidence_only` | 120 | lessons prefixed `Repo map:` |
| `pending-agent-work` | generated / 0.82 / true | none (stays pending) | 30 | outputs/lessons prefixed `Pending:`; unresolved_questions prefixed `Open question:` |
| `rejected-false-positives` | inferred / 0.6 / true | `reject` | 14 | failures prefixed `Rejected:` |
| `stale-assumptions` | generated / 0.72 / true | `mark_stale` | 7 | lessons prefixed `Stale:` |

Notable seeded rules (verbatim, they restate the design doctrine):
- "Rule: OB1 Agent Memory is the runtime-neutral continuity layer for agent work; OpenClaw is the flagship launch runtime, not the product boundary."
- "Rule: Instruction-grade agent memory requires human confirmation or trusted import; inferred and generated memories remain evidence until reviewed."
- "Rule: Write-back stores compact decisions, lessons, failures, next steps, and artifact references; raw transcripts and model reasoning traces are not durable memory."
- "Rule: Code Review Memory is the flagship OpenClaw workflow because repo-specific lessons compound across repeated reviews."
- "Rule: TaskFlow Work Log memory must let a second agent continue without reading a raw transcript."
Rejected exemplars (anti-patterns): auto-promoting all memories to workspace instruction; storing full Slack/meeting/task transcripts in `agent_memories`.
Dashboard status views enumerated: `pending`, `evidence_only`, `confirmed`, `rejected`, `stale`, `all`, plus a traces view.

---

## 5. OpenClaw recipe + JSON Schema contracts (`recipes/openclaw-agent-memory/`)

Flow: OpenClaw task starts → `POST /recall` → scoped memory with provenance and `use_policy` → OpenClaw runs workflow → `POST /writeback` compact work memory → review queue or evidence-only memory → future runtimes reuse governed memory.

Install commands (verbatim):
```bash
openclaw plugins install clawhub:@natebjones/ob1-agent-memory
openclaw skills install nbj-ob1-agent-memory-openclaw
```
Local dev path: link the plugin from `integrations/openclaw-agent-memory/plugin/` instead of the ClawHub package.

Metadata: requires services `["Supabase","OpenRouter"]`, tools `["OpenClaw","Deno","Node.js 20+"]`, `requires_skills: ["openclaw-agent-memory"]`, difficulty advanced, 45 minutes.

**What to recall** (verbatim list): decisions, constraints, lessons, prior attempts, owners, source-backed facts, relevant artifacts. "Do not inject every semantically related thought. The response includes `use_policy`; OpenClaw agents must respect it."

**What to write back** (verbatim list): decisions, outputs, lessons, constraints, unresolved questions, next steps, failures, artifact references. "Do not store transcripts or scratchpads by default. Store source references instead."

### 5.1 `contracts/recall.schema.json` (strict OpenClaw contract; stricter than the API's Zod)
- `$id`: `https://openbrain.dev/schemas/openclaw-agent-memory/recall.v1.json`; `additionalProperties: false` throughout.
- required: `schema_version` (const `openbrain.openclaw.recall.v1`), `workspace_id`, `task_type`, `query`, `entities`, `scope`, `limits`, `sensitivity`.
- `task_type` enum: `["code_review","taskflow_work_log","meeting_to_execution","incident_response","customer_feedback","general"]`.
- `runtime.name` const `"openclaw"`.
- `entities` keys (fixed): `people`, `orgs`, `repos`, `files`, `customers`, `topics` (string arrays).
- `scope` requires all of `visibility` (enum personal/channel/project/workspace/organization), `project_only`, `include_unconfirmed`, `include_stale`.
- `limits` requires `max_items` (1..50), `max_tokens` (100..20000); optional `recency_days` (≥1, nullable).
- `sensitivity` requires `contains_code`, `contains_customer_data`, `contains_private_meeting_data` (booleans).

### 5.2 `contracts/recall-response.schema.json`
- `schema_version` const `openbrain.openclaw.recall_response.v1`; required `request_id`, `memories`.
- Each memory requires: `memory_id`, `summary`, `content`, `source`, `provenance`, `scope`, `use_policy`, `freshness`, `related_artifacts`.
- `provenance.status` enum here: `["observed","inferred","user_confirmed","imported","generated"]` (note: excludes `superseded`/`disputed` which exist in the DB); `created_by` enum `["user","agent","system","import"]`; optional `model`, `runtime`.
- `use_policy` requires the three booleans. `freshness` requires `created_at`; optional `last_confirmed_at`, `stale_after`. `related_artifacts[]` items require `kind`,`uri`.

### 5.3 `contracts/writeback.schema.json`
- `schema_version` const `openbrain.openclaw.writeback.v1`.
- Required (stricter than the API!): `schema_version, workspace_id, idempotency_key, content_hash (minLength 16), models_used, source_refs, memory_payload, provenance, retention, visibility`.
- `runtime.name` const `"openclaw"`.
- `models_used[].role` enum: `["triage","implementation","review","summary","extraction","routing"]` (API Zod allows any string).
- `memory_payload` required keys: `decisions, outputs, lessons, unresolved_questions, next_steps, artifacts, entities` (`constraints`/`failures` optional); artifacts require `kind, uri, description`.
- `provenance.default_status` enum as recall-response (5 values); `confidence` 0..1; `requires_review` boolean — all required.
- `retention` requires `ttl_days` and `stale_after_days` (nullable ints ≥1).
- `visibility` requires `workspace` enum `["private","shared","restricted"]`, `project` enum `["none","private","shared","restricted"]`, `channel` enum `["none","private","shared","restricted"]`. (NOTE: this vocabulary differs from the DB `visibility` column enum — see OPEN QUESTIONS.)

### 5.4 Example payloads
- `examples/recall-request.json`: task_type `code_review`, channel `{kind:"github", id:"NateBJones-Projects/OB1", thread_id:"pull/123"}`, runtime openclaw `2026.3.24-beta.2`, model_intent openai/gpt-5.5, scope project-only conservative, limits `{max_items:8, max_tokens:4000, recency_days:180}`, sensitivity `contains_code:true`.
- `examples/writeback-request.json`: idempotency_key pattern `workspace_123:openclaw:openclaw_task_456:review:8af31e`; provenance generated/0.82/requires_review true; retention `{ttl_days:null, stale_after_days:180}`; visibility `{workspace:"restricted", project:"shared", channel:"restricted"}`.
- `examples/usage-report.json` (NOTE: differs from what the API accepts — see OPEN QUESTIONS):
  ```json
  {
    "used_memory_ids": ["memory_123", "memory_456"],
    "ignored_memory_ids": ["memory_789"],
    "usage_notes": {
      "memory_123": "Used as confirmed project instruction.",
      "memory_456": "Used as supporting evidence.",
      "memory_789": "Ignored because it was stale and evidence-only."
    }
  }
  ```

### 5.5 Portability doctrine (`docs/agent-memory-portability.md`)
- V1 ships on Supabase/Postgres; the requirement is contract cleanliness, not SQLite in v1.
- Adapter boundary table: request shape / storage / ranking / review / audit are contract-level concepts; tables/indexes/embeddings/FTS, scoring implementation, UI, and durable logs are adapter-level.
- If SQLite is built later it is a "migration or sync assistant rather than a second official backend" preserving: the same recall/write-back JSON contracts; export/import for memories, source refs, artifacts, relations, review actions, recall traces, recall items, audit events; FTS5 or sqlite-vec retrieval; idempotency keys and content hashes; evidence-vs-instruction use policy. "Do not fork the runtime contract for SQLite."

---

## 6. Workflow recipe: Code Review Memory (`recipes/openclaw-code-review-memory/`)

"This is the flagship Agent Memory workflow. A stateless reviewer catches a bug once. A memory-backed reviewer keeps repo-specific lessons, maintainer corrections, false positives, and testing expectations available for future reviews."

Flow: PR → recall repo conventions and prior lessons → OpenClaw review agent → findings/fixes/review summary → write reusable repo memory → maintainer review queue → next PR review is more repo-specific.

Quick path: complete base recipe; configure plugin with target OB1 workspace and project; install skill `nbj-ob1-agent-memory-openclaw`; recall before reviewing; write back compact lessons + artifact refs after; consult safe-provenance doc before making review lessons instruction-grade.

**Recall these memory types** (verbatim): repo conventions; prior review comments; recurring bug patterns; risky files or subsystems; test expectations; security-sensitive patterns; maintainer preferences.

**Write back these categories** (verbatim): new recurring issue patterns; review decisions; fixes applied; tests that caught or missed the issue; maintainer corrections; false positives to avoid. "Do not store the full diff or raw review transcript. Store PR, commit, file, or issue references."

**Acceptance criteria** (verbatim):
- Repeated reviews retrieve confirmed repo memory.
- Inferred review lessons remain evidence-only until confirmed.
- Maintainer corrections can supersede older lessons.
- False positives are stored as review guidance, not permanent bans.
- Recall traces show which memories influenced review output.

Examples: recall with task_type `code_review`, `recency_days: 365`, `contains_code: true`, files listed under entities; writeback with `stale_after_days: 365`, lesson exemplar "For OB1 migrations, add service-role-only RLS policies when exposing runtime write APIs.", failure exemplar "A raw PR diff should not be stored in Agent Memory; store a PR link instead." Metadata requires tools `["OpenClaw","GitHub","OB1 Agent Memory API"]`, 30 minutes.

---

## 7. Workflow recipe: TaskFlow Work Log (`recipes/openclaw-taskflow-work-log/`)

Purpose: durable multi-agent handoffs. Sequence: Agent A recalls prior attempts/blockers/constraints → completes one TaskFlow step → writes compact work log → Agent B recalls the work log → continues without the raw transcript. "The handoff lives in OB1 as compact operational memory, not in one model's context window."

Quick path rules: **require recall at TaskFlow start and step resume; require write-back at step completion, pause, or failure**; review high-impact constraints before they become instruction-grade.

**Recall** (verbatim): prior task attempts; blocking issues; relevant decisions; current project constraints; owner and channel context; unresolved questions.
**Write back** (verbatim): what was attempted; what changed; what failed; what remains open; what should be reviewed; what the next agent should know. "The memory should let a second agent continue in minutes without reading the raw transcript."

**Acceptance** (verbatim):
- A second agent can continue from the work log without duplicated attempts.
- Failures are retrievable, but they do not become permanent rules.
- Confirmed project constraints outrank inferred step notes.
- Recall traces show which memories informed the resumed TaskFlow.

Example specifics: recall uses `task_type: "taskflow_work_log"`, `flow_id: "taskflow_agent_memory_launch"`, channel kind `openclaw` with `id: flow_agent_memory_launch`, `thread_id: step_002`, and — distinctively — `include_unconfirmed: true` (handoffs need pending work-log notes), `recency_days: 90`, model_intent anthropic/claude-sonnet. Writeback shows multi-model `models_used` (gemini/gemma-class-local role `triage` + openai/gpt-5.5 role `implementation`), `stale_after_days: 90`, source_ref kind `taskflow_step` uri `openclaw://flows/taskflow_agent_memory_launch/steps/002`, artifact kind `linear_issue` (Linear issue NAT-833 as parent implementation log), constraint "Update Linear at meaningful implementation checkpoints."

---

## 8. How the pieces implement "training over time with governance" (synthesis)

1. **Provenance** — every memory carries `provenance_status` (observed / inferred / user_confirmed / imported / generated / superseded / disputed), `confidence` (0..1), `created_by`, `runtime_name`/`runtime_version`/`provider`/`model`, `task_id`/`flow_id`, plus `agent_memory_source_refs` rows pointing at PRs, docs, steps, sites (kind/uri/title/timestamp).
2. **Review** — write-backs land as `review_status='pending'`; the `/memories/review` queue plus PATCH review actions (confirm / edit / evidence_only / restrict_scope / mark_stale / merge / reject / dispute / supersede) with before/after JSONB snapshots in `agent_memory_review_actions` form the human governance layer. Confirm is the only path that flips a generated memory to instruction-grade (sets provenance to `user_confirmed`, satisfying the DB CHECK).
3. **Use policy** — three booleans per memory (`can_use_as_instruction`, `can_use_as_evidence`, `requires_user_confirmation`) returned in every recall response as `use_policy`; runtimes MUST respect it. DB CHECK constraint makes instruction-grade impossible without confirmed/imported provenance, at the storage layer, not just the API layer.
4. **Evidence-vs-instruction** — default trajectory: generated → pending/evidence → (human confirm) → instruction; or trusted import with `requires_review:false` → instruction immediately. Everything else stays evidence or is demoted (rejected memories lose even evidence status).
5. **Recall** — semantic similarity (embeddings over the linked `thoughts`) is only one input; ranking adds provenance/policy/review/confidence weights so confirmed memory systematically outranks pending memory; scope gates keep personal memory personal and pending memory out of conservative recall.
6. **Recall traces** — every recall writes a trace row (full request payload + response policy) and per-item rows with rank, similarity, ranking score, and a use-policy snapshot; the usage endpoint closes the loop by recording used/ignored(+reason), inspectable via `/recall-traces/:request_id`.
7. **Audit** — 10 event types covering the entire lifecycle (recall_requested, memory_returned, memory_used, memory_ignored, memory_written, memory_confirmed, memory_edited, memory_rejected, memory_superseded, memory_disputed) with actor_kind and payload; unsafe write-backs are audited even though nothing is stored.
8. **Safety firewall** — write-time regex blocking of private keys, API keys, credential-like strings, large code blocks, transcript-like dumps (HTTP 422); doctrine: store compact summaries + artifact/source references, never transcripts or reasoning traces.
9. **Hygiene** — idempotency keys + content hashes for dedupe; `stale_after` for time-decay; lifecycle statuses and relations (supersedes/merged_into/conflicts_with) for knowledge evolution; cleanup harness rejects (never deletes) test memories.

---

## OPEN QUESTIONS

1. **Missing referenced docs.** `docs/safe-agent-memory-provenance.md` (the "operating guide" for provenance/review/use policy/scope decisions), `docs/01-getting-started.md` (core Open Brain + `thoughts` table + `match_thoughts`/`upsert_thought` RPC definitions), `docs/05-tool-audit.md`, and the visual asset pack were not in the digested tree. The exact semantics of the core RPCs (`match_thoughts` signature/return, `upsert_thought` return shape with `.id`) are inferred from call sites only.
2. **Usage-report contract mismatch.** `recipes/openclaw-agent-memory/examples/usage-report.json` uses `ignored_memory_ids` + `usage_notes`, but the deployed API's Zod schema expects `{ used_memory_ids: [], ignored: [{memory_id, reason?}] }`. As written, the example's ignored/notes fields would be silently dropped (defaults applied). Which shape is canonical for v1?
3. **Visibility vocabulary mismatch.** The DB/recall `visibility` enum is `personal|channel|project|workspace|organization`, but the writeback contract's `visibility` object uses `private|shared|restricted|none` per level (workspace/project/channel). The API code ignores the writeback `visibility` object entirely and derives visibility as `project_id ? 'project' : 'personal'`. Intended mapping unknown.
4. **`content_hash`/`idempotency_key` required by contract, optional in API.** The OpenClaw writeback JSON Schema requires both; the API accepts them as optional and computes its own per-row values (request-level `content_hash` is stored nowhere — only per-row hashes computed server-side). Whether clients' `content_hash` is meant to be validated against server hashing is unspecified.
5. **`memory_returned` audit event is defined but never emitted** by the API code (recall emits only `recall_requested`; per-item returns live in `agent_memory_recall_items`). Possibly reserved for future use.
6. **`related_artifacts` in recall responses is always `[]`** in the v1 API even when `agent_memory_artifacts` rows exist; the contract models it fully. Presumably a v1 gap.
7. **`merge`/`supersede` review actions update no fields on the target memory** (no `review_status='merged'` / `lifecycle_status='superseded'` set despite those enum values existing); they only create relation rows + audit. Whether status flips are meant to be manual/dashboard-side is unclear.
8. **`max_tokens` limit is accepted but not enforced** in the recall implementation (only `max_items` truncation); `recency_days` and `entities`/`sensitivity` are likewise accepted but unused in filtering/ranking in v1 code.
9. **Recall retrieval coupling to `thoughts`:** memories are candidate-filtered by `thought_id IN (semantic matches)`; a memory whose `thought_id` is NULL (e.g., `upsert_thought` returned nothing) could only surface via the fallback `limit(100)` recent-rows query when there are zero semantic matches. Edge-case behavior appears unintentional.
10. **Dashboard** (`/agent-memory`, `/agent-memory/traces`, port 3020) and the OpenClaw plugin/skill packages (`clawhub:@natebjones/ob1-agent-memory`, `nbj-ob1-agent-memory-openclaw`, `integrations/openclaw-agent-memory/plugin/`, `skills/openclaw-agent-memory/`) are referenced but their implementations were not in the digested sources.
11. **`inferred` provenance** exists in enums and the seed script but has no rank weight in `rankMemory` (falls to the `: 0` branch, below `generated`'s 0.05) — possibly an oversight.
