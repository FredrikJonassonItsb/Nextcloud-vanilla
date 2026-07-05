# Digest: Open Brain (OB1) Core — Architecture, Schema, MCP Server, Setup

Sources digested (from `natebjones/OB1`):
- `docs/01-getting-started.md` (the core setup guide — "Build Your Open Brain")
- `docs/04-ai-assisted-setup.md` (AI-coding-tool-assisted setup)
- `docs/03-faq.md` (FAQ: connection, imports, architecture, key rotation)
- `AGENTS.md` (agent worktree rules, Linear discipline, product guardrails)
- `CLAUDE.md` (repo map, guard rails, PR standards)
- `docs/05-tool-audit.md` (MCP tool audit & optimization guide)

Repo: `https://github.com/NateBJones-Projects/OB1` (branch `main`). Built by Nate B. Jones; companion to "Your Second Brain Is Closed. Your AI Can't Use It. Here's the Fix." Video walkthrough: Open Brain Startup Guide, https://vimeo.com/1174979042/f883f6489a (~27 min). Community: Open Brain Discord https://discord.gg/Cgh9WJEkeG (`#help`, `#show-and-tell` channels); Substack community https://natesnewsletter.substack.com/.

---

## 1. What Open Brain Is

A persistent AI memory system: **one database (Supabase Postgres + pgvector), one MCP protocol, any AI client.** Thoughts are stored as raw text + 1536-dimensional vector embeddings + structured JSONB metadata. A single Supabase Edge Function (`open-brain-mcp`) is the MCP server: any full-MCP client can read AND write; restricted ChatGPT sessions get read-only `search`/`fetch` aliases. The OB1 repo additionally contains community extensions, recipes, schemas, dashboards, integrations, and skills built on top of this core.

Explicit positioning (from FAQ):
- NOT a note-taking app; NOT an Obsidian replacement or companion layer. It is "a memory layer for your AI" — a database with vector search. Obsidian content can be migrated IN via the "Second Brain Migration" companion prompt, but Obsidian does not sit alongside/on top of it.
- No visual editing frontend exists in core. Supabase Table Editor (dashboard → Table Editor → thoughts) is the manual edit path. A web dashboard on top is a suggested user-built project. (Note: an optional `OPEN_BRAIN_CITATION_BASE_URL` secret exists for pointing ChatGPT citations at such a dashboard.)
- Anti-noise strategy is siloing by context via metadata (not "forgetting"). Retrieval layer matters more than storage layer.

**Two paid-ish services, total cost:** Supabase (free tier) + OpenRouter (~$5 in credits, "lasts months"). Setup time ~30 minutes, zero coding experience assumed.

**License:** FSL-1.1-MIT. No commercial derivative works.

---

## 2. Complete Database Schema (Supabase Postgres)

### 2.1 Prerequisite
Enable the `pgvector` extension: Supabase dashboard → Database → Extensions → search "vector" → toggle ON. (SQL equivalent used in FAQ troubleshooting: `create extension if not exists vector;`)

### 2.2 The `thoughts` table + indexes + updated_at trigger (Step 2.2, verbatim)

```sql
-- Create the thoughts table
create table thoughts (
  id uuid default gen_random_uuid() primary key,
  content text not null,
  embedding vector(1536),
  metadata jsonb default '{}'::jsonb,
  created_at timestamptz default now(),
  updated_at timestamptz default now()
);

-- Index for fast vector similarity search
create index on thoughts
  using hnsw (embedding vector_cosine_ops);

-- Index for filtering by metadata fields
create index on thoughts using gin (metadata);

-- Index for date range queries
create index on thoughts (created_at desc);

-- Auto-update the updated_at timestamp
create or replace function update_updated_at()
returns trigger as $$
begin
  new.updated_at = now();
  return new;
end;
$$ language plpgsql;

create trigger thoughts_updated_at
  before update on thoughts
  for each row
  execute function update_updated_at();
```

### 2.3 Semantic search function `match_thoughts` (Step 2.3, verbatim)

```sql
create or replace function match_thoughts(
  query_embedding vector(1536),
  match_threshold float default 0.7,
  match_count int default 10,
  filter jsonb default '{}'::jsonb
)
returns table (
  id uuid,
  content text,
  metadata jsonb,
  similarity float,
  created_at timestamptz
)
language plpgsql
as $$
begin
  return query
  select
    t.id,
    t.content,
    t.metadata,
    1 - (t.embedding <=> query_embedding) as similarity,
    t.created_at
  from thoughts t
  where 1 - (t.embedding <=> query_embedding) > match_threshold
    and (filter = '{}'::jsonb or t.metadata @> filter)
  order by t.embedding <=> query_embedding
  limit match_count;
end;
$$;
```

Notes: cosine distance operator `<=>`; similarity = `1 - distance`; default threshold 0.7, default count 10; optional JSONB containment filter (`metadata @> filter`).

### 2.4 Row Level Security (Step 2.4, verbatim)

```sql
alter table thoughts enable row level security;

create policy "Service role full access"
  on thoughts
  for all
  using (auth.role() = 'service_role');
```

### 2.5 Table grants — REQUIRED on new Supabase projects (Step 2.5, verbatim)

```sql
-- Allow the service_role to read and write thoughts
grant select, insert, update, delete on table public.thoughts to service_role;
```

Rationale (important gotcha): Supabase no longer grants full table permissions to `service_role` by default on new projects. Without this GRANT, the MCP server returns **"permission denied for table thoughts"** on capture/search.

### 2.6 Deduplication: fingerprint column + `upsert_thought` (Step 2.6, verbatim)

```sql
-- Add fingerprint column for deduplication
ALTER TABLE thoughts ADD COLUMN content_fingerprint TEXT;

-- Unique index so duplicate content is detected
CREATE UNIQUE INDEX idx_thoughts_fingerprint
  ON thoughts (content_fingerprint)
  WHERE content_fingerprint IS NOT NULL;

-- Upsert function: inserts new thoughts, merges metadata on duplicates
CREATE OR REPLACE FUNCTION upsert_thought(p_content TEXT, p_payload JSONB DEFAULT '{}')
RETURNS JSONB AS $$
DECLARE
  v_fingerprint TEXT;
  v_result JSONB;
  v_id UUID;
BEGIN
  v_fingerprint := encode(sha256(convert_to(
    lower(trim(regexp_replace(p_content, '\s+', ' ', 'g'))),
    'UTF8'
  )), 'hex');

  INSERT INTO thoughts (content, content_fingerprint, metadata)
  VALUES (p_content, v_fingerprint, COALESCE(p_payload->'metadata', '{}'::jsonb))
  ON CONFLICT (content_fingerprint) WHERE content_fingerprint IS NOT NULL DO UPDATE
  SET updated_at = now(),
      metadata = thoughts.metadata || COALESCE(EXCLUDED.metadata, '{}'::jsonb)
  RETURNING id INTO v_id;

  v_result := jsonb_build_object('id', v_id, 'fingerprint', v_fingerprint);
  RETURN v_result;
END;
$$ LANGUAGE plpgsql;
```

Fingerprint normalization: whitespace collapsed to single spaces (`regexp_replace(content, '\s+', ' ', 'g')`), trimmed, lowercased, UTF8-encoded, SHA-256, hex. On duplicate: `updated_at` refreshed, metadata merged with `||` (no second row).

### 2.7 Schema verification (done-when)
Table Editor shows `thoughts` with columns: `id, content, embedding, metadata, content_fingerprint, created_at, updated_at`. Database → Functions shows `match_thoughts` and `upsert_thought`.

### Schema guard rails (CLAUDE.md)
- **Never modify the core `thoughts` table structure.** Adding columns is fine; altering/dropping existing columns is not.
- No `DROP TABLE`, `DROP DATABASE`, `TRUNCATE`, or unqualified `DELETE FROM` in SQL files.

---

## 3. Embedding & Metadata Pipeline

- **AI gateway: OpenRouter** (openrouter.ai) — one account/key/billing for all models; used for (a) embeddings and (b) lightweight LLM metadata extraction. Chosen over direct OpenAI to future-proof for Claude/Gemini/etc.
- **Embedding dimension: 1536** (hard-coded in `vector(1536)` columns and `match_thoughts` signature). Model swap: edit the model strings in the Edge Function code and redeploy; browse models at openrouter.ai/models; "just make sure embedding dimensions match (1536 for the current setup)." (The exact model IDs live in `server/index.ts`, not in these docs — see OPEN QUESTIONS.)
- **Capture flow (from any MCP client):** AI client sends text to `capture_thought` tool → MCP server generates the 1536-dim embedding AND extracts metadata via LLM **in parallel** → both stored as a single row in `thoughts` → confirmation (with extracted metadata: type, topics, people, action items) returned to the AI.
- **Search flow:** client sends query to the MCP Edge Function → function embeds the question → Supabase `match_thoughts` ranks stored thoughts by vector cosine similarity → results returned ranked by meaning, not keywords.
- Metadata extraction is **best-effort** (LLM best-guess with limited context); embedding powers retrieval regardless of metadata quality. Capture templates (in companion prompts, `docs/02-companion-prompts.md`) give the LLM clearer classification signals.
- **Cost note:** embeddings ~$0.02 per million tokens — "basically free even at scale." $5 OpenRouter credit lasts months.

### Data-modeling guidance (FAQ, architecture section)
- Unit of embedding = one retrievable idea per row. Atomic/Zettelkasten-style notes are near-perfect; long documents must be **chunked** into sections, each chunk embedded separately, with metadata linking chunks to a parent document (suggested pattern: parent document table + chunks table with embeddings; hybrid querying = filter on structured metadata first via normal Postgres indexes, then vector similarity within the filtered set).
- Structured data granularity: health/sleep = one entry per night; calendar = one per event; email = one per email or thread; tasks = one per task.
- Every source type should self-tag in metadata, e.g. `source: "garmin"`, `source: "obsidian"`, `source: "calendar"`, so retrieval can be filtered by context.
- Row count is not a concern: Postgres handles millions of rows; HNSW index keeps search fast at scale.
- Semantic search quality is sparse below ~20–30 rows — "not broken, it's sparse."

---

## 4. MCP Server (Edge Function `open-brain-mcp`)

### 4.1 Shape
- One Supabase Edge Function (Deno), function name **`open-brain-mcp`**, deployed with `--no-verify-jwt`.
- Source files fetched from repo: `server/index.ts` and `server/deno.json`:
  - `https://raw.githubusercontent.com/NateBJones-Projects/OB1/main/server/index.ts`
  - `https://raw.githubusercontent.com/NateBJones-Projects/OB1/main/server/deno.json`
- First line of the real `index.ts` (used as download verification): `import "jsr:@supabase/functions-js/edge-runtime.d.ts";` (if you see `console.log("Hello from Functions!")` the curl didn't overwrite the CLI starter file).
- Live URL: `https://YOUR_PROJECT_REF.supabase.co/functions/v1/open-brain-mcp`
- **MCP Connection URL** (for clients that can't send headers): `https://YOUR_PROJECT_REF.supabase.co/functions/v1/open-brain-mcp?key=your-access-key-from-step-5`
- Transport: remote HTTP MCP (Claude Code uses `--transport http`).

### 4.2 Tools exposed (core four + ChatGPT aliases)
| Tool | Kind | Purpose |
|---|---|---|
| `capture_thought` | write (bounded, non-destructive) | Save a thought: embed + LLM metadata extraction in parallel, store row |
| `search_thoughts` | read | Semantic/vector search |
| `list_thoughts` | read | Browse recent thoughts |
| `thought_stats` | read | Stats overview (counts, e.g. "who do I mention most") |
| `search` | read (ChatGPT compatibility alias) | Standard read-only search path |
| `fetch` | read (ChatGPT compatibility alias) | Standard read-only fetch path; citation URLs use `OPEN_BRAIN_CITATION_BASE_URL` if set |

ChatGPT annotation detail (FAQ + tool-audit, load-bearing): ChatGPT treats MCP tools **without `readOnlyHint`** as write actions. Open Brain marks `search_thoughts`, `list_thoughts`, `thought_stats`, `search`, `fetch` as read-only, and `capture_thought` as a bounded non-destructive write. Recommended annotation pattern for any custom tool: read tools `annotations: { readOnlyHint: true }`; write tools `annotations: { readOnlyHint: false, openWorldHint: false, destructiveHint: false }` unless the tool truly touches arbitrary external resources or destroys data. Restricted ChatGPT Pro/read-only sessions may hide/block `capture_thought` while `search`/`fetch` still work.

### 4.3 Auth
- Custom shared-secret access key, checked by the server on every request. Two accepted transports:
  - Query parameter: `?key=<access-key>` (needed for Claude Desktop, Claude Web, ChatGPT — they cannot send custom headers; set client auth to "none"/"No Authentication").
  - HTTP header: `x-brain-key: <access-key>` (lowercase, with dash) — for Claude Code / mcp-remote.
- Key generation (64 hex chars):
  - Mac/Linux: `openssl rand -hex 32`
  - Windows PowerShell: `-join ((1..32) | ForEach-Object { '{0:x2}' -f (Get-Random -Maximum 256) })`
- Stored server-side as Supabase secret `MCP_ACCESS_KEY`. Mismatch between the secret and the URL/header value → 401.
- Design stance: it is **one access key for all of Open Brain** — core plus every extension. Never regenerate unless you intend to replace it for ALL deployed functions.
- The project-ref in the URL is treated as obscurity only; the key closes the gap.

### 4.4 Environment variables / secrets (Edge Function)
| Name | How set | Purpose |
|---|---|---|
| `MCP_ACCESS_KEY` | `supabase secrets set MCP_ACCESS_KEY=...` | Request auth (query `key` or `x-brain-key` header must match) |
| `OPENROUTER_API_KEY` | `supabase secrets set OPENROUTER_API_KEY=...` | Embeddings + LLM metadata extraction via OpenRouter (key format `sk-or-v1-...`) |
| `SUPABASE_URL` | automatic inside Edge Functions | DB access |
| `SUPABASE_SERVICE_ROLE_KEY` | automatic inside Edge Functions | DB access as service_role |
| `OPEN_BRAIN_CITATION_BASE_URL` | optional secret | Base URL for citation links returned by ChatGPT `search`/`fetch` compatibility tools |

Secret rotation gotcha (FAQ, exact behavior): Edge Functions read env vars **once at cold start and cache them**. After `supabase secrets set`, warm instances keep the old value (intermittent 401s for minutes). Always redeploy to force fresh boot: `supabase functions deploy open-brain-mcp --no-verify-jwt` (or bare `supabase functions deploy` to redeploy all functions, including extensions that also read `OPENROUTER_API_KEY`). Also update local `.env` files (e.g. `recipes/chatgpt-conversation-import/.env`) and CI/CD configs. Verify a new OpenRouter key: `curl https://openrouter.ai/api/v1/models -H "Authorization: Bearer sk-or-v1-your-new-key"` → JSON model list = valid, 401 = wrong/not active. Then immediately capture+search a test thought.

---

## 5. Full Setup Procedure (Step-by-step, from 01-getting-started.md)

Credential tracker spreadsheet (xlsx) is mandatory-first: `https://raw.githubusercontent.com/NateBJones-Projects/OB1/main/docs/open-brain-credential-tracker.xlsx`. It auto-generates MCP Server URL / MCP Connection URL in its Step 6 section and has ready-to-copy client values in Step 7. Tracked values: Database password, Project ref, Project URL, Secret key, OpenRouter API key, MCP Access Key, MCP Server URL, MCP Connection URL.

**Step 1 — Create Supabase project.** supabase.com → New Project → name `open-brain` → strong DB password (save NOW) → nearest region → create (1–2 min). Project ref = random string in dashboard URL `supabase.com/dashboard/project/THIS_PART`.

**Step 2 — Database.** 2.1 enable pgvector; 2.2 thoughts table SQL; 2.3 `match_thoughts`; 2.4 RLS; 2.5 GRANT to service_role (required!); 2.6 dedup fingerprint + `upsert_thought`; 2.7 verify columns/functions. All via SQL Editor → New query, one block at a time.

**Step 3 — Connection details.** Settings (gear) → API Keys → "Publishable and secret API keys" tab. Copy **Project URL** and a **Secret key** (the `default` one, or create a dedicated `open-brain` secret key for easier later revocation). New-format keys — the "Legacy anon, service_role API keys" tab (old JWT style) is NOT needed. Secret key = password-grade; Publishable key not needed for this setup.

**Step 4 — OpenRouter.** Sign up at openrouter.ai → openrouter.ai/keys → Create Key named `open-brain` → copy immediately → add $5 credits.

**Step 5 — Access key.** Generate 64-hex-char key (commands in §4.3). Save as "MCP Access Key".

**Step 6 — Deploy the MCP server.** Pre-warning: a stale `~/supabase` folder from a previous attempt "will silently hijack your setup" — delete it (`rm -rf ~/supabase` / `Remove-Item -Recurse ~\supabase`).
1. Create a project folder (e.g. `open-brain`), `cd` into it; every subsequent command runs from there.
2. Install Supabase CLI — Mac: `brew install supabase/tap/supabase` (Homebrew installer: `/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)")`; `npm install -g supabase` is NOT supported; `npx supabase ...` works as fallback. Windows: Scoop — `Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser`; `Invoke-RestMethod -Uri https://get.scoop.sh | Invoke-Expression`; `scoop bucket add supabase https://github.com/supabase/scoop-bucket.git`; `scoop install supabase`. Verify: `supabase --version`.
3. `supabase login` (browser auth).
4. `supabase init` (creates `supabase/` folder — if `ls supabase/` fails you're in the wrong directory), then `supabase link --project-ref YOUR_PROJECT_REF`.
5. `supabase secrets set MCP_ACCESS_KEY=...` and `supabase secrets set OPENROUTER_API_KEY=...` (must exactly match tracker or 401s).
6. `supabase functions new open-brain-mcp`, then download `index.ts` and `deno.json` from the repo raw URLs into `supabase/functions/open-brain-mcp/` (curl on Mac, `Invoke-WebRequest` on Windows). Verify first line is the jsr import (see §4.1).
7. `supabase functions deploy open-brain-mcp --no-verify-jwt`. Check first deploy-output line says `Using workdir <your project folder>` — home directory means wrong project structure; start over from a clean folder.
Done when: MCP Server URL + MCP Connection URL recorded and `supabase functions list` shows `open-brain-mcp` as `ACTIVE`.

**Step 7 — Connect AI clients.**
- 7.1 Claude Desktop (macOS/Windows official app): Settings → Connectors → Add custom connector → Name `Open Brain` → Remote MCP server URL = full MCP Connection URL (with `?key=`) → Add. Enable per conversation via "+" → Connectors. No JSON, no Node, no terminal.
- 7.2 ChatGPT (paid plan: Plus/Pro/Business/Enterprise/Edu; chatgpt.com web only, not mobile; custom MCP is beta and plan/model-sensitive as of May 2026). One-time: Settings → Apps & Connectors → Advanced settings → Developer mode ON (this **disables ChatGPT built-in Memory** — OpenAI requirement). Then Apps & Connectors → Create → Name `Open Brain` → description `Personal knowledge base with semantic search` → MCP endpoint URL = MCP Connection URL → Authentication: **No Authentication** (key in URL) → Create. ChatGPT often needs explicit tool naming at first ("Use the Open Brain search_thoughts tool to..."). Thinking models expose more tools than some Pro variants.
- 7.3 Claude Code, one command:
  ```bash
  claude mcp add --transport http open-brain \
    https://YOUR_PROJECT_REF.supabase.co/functions/v1/open-brain-mcp \
    --header "x-brain-key: your-access-key-from-step-5"
  ```
- 7.4 OpenAI Codex, `~/.codex/config.toml`:
  ```toml
  [mcp_servers.open-brain]
  command = "npx"
  args = [
    "-y",
    "mcp-remote",
    "https://YOUR_PROJECT_REF.supabase.co/functions/v1/open-brain-mcp?key=your-access-key-from-step-5"
  ]
  startup_timeout_sec = 30
  ```
  `startup_timeout_sec = 30` is required — default 10 s timeout is too short for mcp-remote to reach the Supabase edge function (`MCP client for open-brain timed out after 10 seconds`).
- 7.5 Other clients (Cursor, VS Code Copilot, Windsurf): Option A = URL with `?key=`; Option B (recommended for stdio-only clients, needs Node.js) = `supergateway` bridge:
  ```json
  {
    "mcpServers": {
      "open-brain": {
        "command": "npx",
        "args": [
          "-y",
          "supergateway",
          "--streamableHttp",
          "https://YOUR_PROJECT_REF.supabase.co/functions/v1/open-brain-mcp?key=your-access-key-from-step-5"
        ]
      }
    }
  }
  ```
  Option C = `mcp-remote` bridge (does OAuth discovery on startup → can time out with Supabase Edge URLs; set 30+ s startup timeout):
  ```json
  {
    "mcpServers": {
      "open-brain": {
        "command": "npx",
        "args": [
          "-y",
          "mcp-remote",
          "https://YOUR_PROJECT_REF.supabase.co/functions/v1/open-brain-mcp",
          "--header",
          "x-brain-key:${BRAIN_KEY}"
        ],
        "env": { "BRAIN_KEY": "your-access-key-from-step-5" }
      }
    }
  }
  ```
  Note: NO space after the colon in `x-brain-key:${BRAIN_KEY}` — some clients mangle spaces inside args.
Done when: client shows `search_thoughts`, `list_thoughts`, `thought_stats`, `capture_thought` (ChatGPT may additionally show `search`, `fetch`).

**Step 8 — Use it / smoke test.** Prompt-to-tool routing table (verbatim intent examples):
| Prompt | Tool used |
|---|---|
| "Save this: decided to move the launch to March 15 because of the QA blockers" | Capture thought |
| "Remember that Marcus wants to move to the platform team" | Capture thought |
| "What did I capture about career changes?" | Semantic search |
| "What did I capture this week?" | Browse recent |
| "How many thoughts do I have?" | Stats overview |
| "Find my notes about the API redesign" | Semantic search |
| "Show me my recent ideas" | Browse + filter |
| "Who do I mention most?" | Stats |

Canonical test: capture `Remember this: Sarah mentioned she's thinking about leaving her job to start a consulting business` → AI confirms with extracted metadata (type, topics, people, action items) → verify one row in Table Editor → search `What did I capture about Sarah?` → thought retrieved.

---

## 6. Troubleshooting Knowledge Base (getting-started + FAQ, merged)

- **Claude Desktop tools missing:** connector added in Settings → Connectors? Enabled for the conversation ("+" → Connectors)? Remove and re-add with same URL. Linux/community ports not covered.
- **"Claude Desktop / ChatGPT auth error but Claude Code works":** THE most common issue. Desktop/Web/ChatGPT cannot send custom headers — use the `?key=` URL form with auth set to "none", not the `x-brain-key` header.
- **ChatGPT says tool unavailable:** check Supabase → Edge Functions → `open-brain-mcp` → Logs. **Zero requests while ChatGPT fails ⇒ ChatGPT never called the server; the problem is the tools exposed to that chat session, not keys/URLs/code.** Redeploy server, refresh/recreate the ChatGPT app (to pull new tool metadata), fresh chat, thinking model. Read-only sessions: expect `search`/`fetch` to work while `capture_thought` is hidden/blocked.
- **"Permission denied for table thoughts":** missing Step 2.5 GRANT (new-project behavior). Run the GRANT SQL, retry.
- **401 errors:** `?key=` value ≠ `MCP_ACCESS_KEY` secret; or header not exactly `x-brain-key` (lowercase, dash).
- **Search returns nothing:** capture at least one thought first; ask AI to "search with threshold 0.3" for a wider net; check Edge Function logs.
- **Capture works but search doesn't:** DB/functions/MCP fine — isolate to search: vector extension not enabled (`create extension if not exists vector;`), embedding generation failing silently, or search function deployed with an error. Check Logs tab.
- **Slow responses:** cold-start on first call is normal; consistently slow ⇒ wrong Supabase region.
- **Wrong metadata:** extraction is best-effort; use capture templates from the prompt kit for consistent classification.
- **Sub-par search:** (1) row count under 20–30 = sparse, not broken; (2) test with near-verbatim words first; (3) check edge function logs; (4) found-by-list-but-not-by-search = embedding/similarity gap that improves with more data.
- **Meta-rule (repeated in FAQ, 04, and quick reference):** *configuration problems need configuration fixes* — do NOT let an AI rewrite the working Edge Function code; check `supabase secrets list`, URL key, skipped steps, and Edge Function logs first.
- **AI-improvisation hazard (04):** if the AI can't read the source it invents plausible-but-wrong Edge Function code (happened when the guide lived on Substack with collapsed code blocks). If the AI generates setup code from scratch, stop it and point it back to `docs/01-getting-started.md`.
- Supabase's own AI assistant (chat icon, bottom-right of dashboard) is the recommended debugging partner for anything Supabase-specific.
- Quick-reference checklist before asking for help: (1) followed guide step by step? (2) Edge Function logs; (3) URL format `https://your-ref.supabase.co/functions/v1/open-brain-mcp?key=your-key`; (4) Supabase AI assistant; (5) don't let AI rewrite server code.

---

## 7. Capture Sources & Imports (referenced from core docs)

- **Slack Capture** (`integrations/slack-capture/`): type thoughts in a Slack channel → automatically embedded and stored. From 04 and FAQ we additionally know: there is an **`ingest-thought`** Edge Function (named alongside `open-brain-mcp` as "fully written in the guide"); Slack setup involves creating a Slack app, OAuth scopes, install to workspace, Event Subscriptions (a missing **`message.groups`** event subscription is a named failure mode); a **Channel ID** is captured at "Step 5" of that build and used at "Step 7"; the Slack guide's Part 1 = Capture, Part 2 = Retrieval, with a specific test message/expected response at "Step 9". Full details live in the integration's own docs (not among digested sources).
- **Recipes** (bulk import, each ~30 min): `recipes/email-history-import/` (Gmail via OAuth; needs a Google Cloud project with Gmail API enabled; pulls by label + time window; strips signatures/quoted replies/auto-generated; stores sender/subject/date metadata). `recipes/chatgpt-conversation-import/` (processes the ChatGPT JSON export from Settings → Data controls → Export data; extracts meaningful exchanges). Community importers in flight: Google Activity (Takeout), Twitter/X archives, Claude conversations, Gemini.
- **Companion prompts** (`docs/02-companion-prompts.md`, referenced): **Memory Migration** (pull what your AI already knows into the brain — run first), **Second Brain Migration** (Notion/Obsidian/etc. import), **Open Brain Spark** (personalized use-case discovery), **Quick Capture Templates** (five patterns for clean metadata extraction), **The Weekly Review** (Friday ritual: themes, forgotten action items, missed connections).

---

## 8. MCP Tool Surface Optimization (docs/05-tool-audit.md)

Audience: anyone with multiple MCP servers or one server with >10 tools; symptoms: slow responses, ignored tools, misrouted tool calls.

**Economics:** a typical MCP tool definition costs 150–400 tokens (name + description + parameter schema), loaded on every message. 40 tools ≈ 6,000–16,000 tokens of standing overhead. Claude's MCP Tool Search defers loading when definitions exceed ~10% of context (≈85% context-overhead reduction) but does NOT fix routing accuracy. Under ~10 total tools: fine; over 20: optimize. Reference community evidence: Issue #36 (MCP Scoping & Cross-Entity Orchestration) — Claude Opus handles ~40 tools in scripted use, degrades on ambiguous multi-domain prompts; weaker models struggle significantly. Also Issue #61 (Standardize Ingestion Patterns).

**Bloat signatures:** per-table CRUD sets (6 tools/table × 5–8 tables = 30–48 tools); duplicate search tools across servers; never-used update/delete tools; overlapping descriptions ("Search for...").

**Merge patterns:**
- Pattern A — unified CRUD: one `manage_recipe` with `action: "create" | "read" | "update" | "delete" | "list"`, `id` (read/update/delete), `data` (create/update), `filters` (list). ~800–1,500 tokens saved/table; needs a strong model.
- Pattern B — read/write split: `save_recipe` (upsert) + `query_recipes` (search/filter/get-by-ID/list). ~500–1,000 tokens/table; maps to "save this"/"find that" natural language.
- Pattern C — generic entity manager: `save_entity(entity_type, data)` + `query_entities(entity_type, filters{search?, category?, date_range?})` + `get_entity_detail(entity_type, id)`. Works when tables share the `user_id` + timestamps + domain-fields pattern; falls apart with heterogeneous schemas.
- Do NOT merge: cross-extension bridge tools (`link_thought_to_contact`, `link_contact_to_professional_crm`), unique-workflow tools (`generate_shopping_list`), high-frequency core tools (`capture_thought`, `search_thoughts` benefit from individual names).

**Three-server scoping pattern** (each scoped server = its own Edge Function; same database; scoping controls tool exposure, not data access; add each as a separate Claude Desktop connector, connect selectively):
- Capture server (write-heavy): `capture_thought`, `save_entity`/`add_*`, `log_interaction`, `log_maintenance`. ~5–8 tools, ~1,500–3,000 tokens.
- Query server (read-heavy): `search_thoughts`, `query_entities`/`search_*`/`get_*`, `get_upcoming_maintenance`, `get_week_schedule`, `get_pipeline_overview`, bridge tools. ~8–12 tools, ~3,000–5,000 tokens.
- Admin server (rarely connected): `update_*`/`delete_*`, bulk ops, migrations, anything used < weekly.
Decision questions per tool: when used (capture/research/maintenance)? how often (daily→capture/query, weekly→query, ≤monthly→admin)? does it need neighbors (co-locate `generate_shopping_list` with `get_meal_plan`)?

**Four ready-to-paste prompt kits** (full texts in doc 05, structure noted here): (1) *Audit My MCP Tools* — inventory grouped by entity, flags "CRUD set — consolidation candidate", search overlap, orphan tools; outputs Tool Inventory, Estimated Context Cost (% of 200k and 128k windows), Consolidation Opportunities, prioritized Recommended Actions; ends "Want me to draft the merged tool definitions for any of these recommendations?". (2) *Suggest Tool Merges* — before/after per group with savings table. (3) *Design My MCP Scoping* — interactive Q&A (four questions asked one at a time) → 2–4 server plan with per-server context cost. (4) *Estimate My Context Cost* — heuristics: name ~5 tokens, description words × 1.3, params 20–40 tokens each, enum values ~3 each, required/optional meta ~5/param; reports % of Claude 200k / ChatGPT 128k / Gemini 1M; verdicts "lean / moderate / heavy"; if >5% of any window, name top-3 heaviest tools. Quick audit one-liner also provided ("List every MCP tool... group by server... total count").

**Official extension benchmark** (tools per extension): 1. Household Knowledge 5; 2. Home Maintenance 4; 3. Family Calendar 6; 4. Meal Planning 6 + 4 shared; 5. Professional CRM 7 (+1 bridge to core); 6. Job Hunt Pipeline 8 (+1 bridge to CRM). All 6 together = **40 tools, 2 bridges**. Designed for selective connection.

---

## 9. Repo Governance (CLAUDE.md + AGENTS.md)

### Repo structure
```
extensions/     — Curated, ordered learning path (6 builds). Do NOT add without maintainer approval.
primitives/     — Reusable concept guides (must be referenced by 2+ extensions). Curated.
recipes/        — Standalone capability builds. Open for community contributions.
schemas/        — Database table extensions. Open.
dashboards/     — Frontend templates (Vercel/Netlify). Open.
integrations/   — MCP extensions, webhooks, capture sources. Open.
skills/         — Reusable AI client skills and prompt packs. Open.
docs/           — Setup guides, FAQ, companion prompts.
resources/      — Official companion files and packaged exports.
```
Every contribution: own subfolder under the right category, must include `README.md` + `metadata.json`.

### Guard rails (verbatim intent)
1. Never modify core `thoughts` table structure (add columns OK; alter/drop not).
2. No credentials/API keys/secrets in files — env vars only.
3. No binary blobs >1MB; no `.exe`, `.dmg`, `.zip`, `.tar.gz`.
4. No `DROP TABLE` / `DROP DATABASE` / `TRUNCATE` / unqualified `DELETE FROM` in SQL files.
5. No profanity anywhere (docs, examples, seed data, UI copy, prompts, walkthroughs, generated assets).
6. **MCP servers must be remote (Supabase Edge Functions), not local.** Never use `claude_desktop_config.json`, `StdioServerTransport`, or local Node.js servers. All extensions deploy as Edge Functions and connect via Claude Desktop's custom connectors UI.

### PR standards
- Title: `[category] Short description` (e.g., `[recipes] Email history import via Gmail API`, `[skills] Panning for Gold standalone skill pack`).
- Branch: `contrib/<github-username>/<short-description>`.
- Commit prefixes: `[category]`.
- Gate: `.github/workflows/ob1-gate-v2.yml` (automated PR checks, must pass before human review); `.github/workflows/claude-review.yml` (maintainer-triggered Claude review); `.github/metadata.schema.json` (metadata.json validation); `.github/PULL_REQUEST_TEMPLATE.md`; `CONTRIBUTING.md` = source of truth for contribution rules.

### Parallel agent worktrees (identical block in CLAUDE.md and AGENTS.md)
- One Git worktree per agent/task/PR-sized workstream; canonical checkout only for pulls/inspection/creating worktrees; assigned absolute worktree path is the boundary ("The chat is not the boundary").
- Assignment template (verbatim): `Repository worktree:\n/ABSOLUTE/PATH/TO/PROJECT-WORKTREE\n\nBranch:\ncodex/SHORT-TASK-NAME\n\nTask:\nDESCRIBE THE EXACT WORK.`
- Rules: don't switch branches in the canonical repo mid-work; don't edit sibling worktrees; `git status --short` before staging, stage only task files; pause before merge/rebase if upstream moved (unless task says finish end to end); after merge + clean, `git worktree remove /ABSOLUTE/PATH/...`.
- Quick checks: sudden branch changes ⇒ two chats in one working dir; "branch already checked out" on `git worktree add` ⇒ new branch name or remove old clean worktree; failed cleanup ⇒ inspect `git status --short`, preserve uncommitted work.

### Linear + product guardrails (AGENTS.md, maintainer-side)
- Feature work tied to a Linear issue: update Linear at start, meaningful checkpoints, and before handback; parent issue is the living implementation log. OB1 Agent Memory / OpenClaw launch work parent: **`NAT-833`** (record architecture notes, milestones, blockers, verification results). Capture schema/API-contract/trust-policy/workflow/publishing-path decisions in Linear immediately.
- `OB1 Agent Memory` stays runtime-neutral; OpenClaw is the flagship launch runtime, not the product boundary.
- Trust model: inferred/generated memory = evidence by default; instruction-grade memory requires human confirmation or trusted import. Avoid storing raw transcripts, model reasoning traces, secrets, and large code blocks by default.
- Docs style: diagram-first (diagram, short explanation, copy-paste setup, deeper reference).
- Provenance: carry Nate B. Jones / OB1 branding subtly through product surfaces; every public asset points naturally to https://substack.com/@natesnewsletter and https://natebjones.com; CTA "earned, not marketing." ClawHub/OpenClaw publishing must use `@natebjones` / Nate OB1 namespace — if unavailable, stop and record the blocker (never fall back to Jonathan's personal handle).
- Maintainer-local GSD layer in `.planning/` (gitignored): start with `.planning/STATE.md`, then `PROJECT.md`, `ROADMAP.md`, `codebase/*.md`. Not part of the public contribution contract.

### AI-assisted setup workflow (docs/04)
Canonical kickoff prompt: **"Read `docs/01-getting-started.md` and walk me through building my Open Brain step by step."** AI handles SQL, Edge Function deploy, CLI, log-based debugging. Human handles all web-UI clicking: account creation (Supabase/OpenRouter/Slack), dashboard settings, Slack app config, client connector setup. Go step by step, not "set up the whole thing." Origin note: Matt Hallett built the first Open Brain entirely through Cursor with Claude.

---

## 10. Rebuild Checklist (condensed operational order)

1. Supabase project (`open-brain`), save Project ref + DB password.
2. Enable pgvector; run SQL blocks 2.2 → 2.6 in order (table+indexes+trigger, `match_thoughts`, RLS, GRANT to service_role, dedup + `upsert_thought`).
3. Save Project URL + Secret key (new-format keys).
4. OpenRouter account, key `open-brain`, $5 credits.
5. Generate 64-hex MCP access key.
6. Supabase CLI: login → init in a dedicated folder → link → `secrets set MCP_ACCESS_KEY / OPENROUTER_API_KEY` → `functions new open-brain-mcp` → drop in `server/index.ts` + `server/deno.json` → `functions deploy open-brain-mcp --no-verify-jwt` → confirm `ACTIVE`.
7. Connect clients (query-param key for Desktop/ChatGPT; `x-brain-key` header for Claude Code; supergateway/mcp-remote bridges for stdio clients).
8. Smoke test: capture the Sarah thought, verify Table Editor row, semantic-search it back.
9. Optional: Slack capture integration (`ingest-thought` function), Gmail/ChatGPT import recipes, companion prompts (Memory Migration first).

---

## OPEN QUESTIONS

1. **Exact model IDs are not in these docs.** The embedding model and the metadata-extraction LLM are configured as "model strings in the Edge Function code" (`server/index.ts`), which was not among the digested sources. Known constraints: OpenRouter gateway, 1536-dim embeddings (strongly implying an OpenAI `text-embedding-3-small`-class model), embeddings priced ~$0.02/M tokens, metadata extraction described as a "lightweight LLM." Rebuilders need `server/index.ts` (raw URL in §4.1) for the authoritative model strings, tool schemas, annotation JSON, and auth-check implementation.
2. **`server/deno.json` contents** (dependency pins) not captured — fetch from the raw URL.
3. **Metadata JSONB shape** is only known by example: fields like type, topics, people, action items, plus conventions like `source: "garmin"`. The exact extraction prompt/schema lives in `server/index.ts`.
4. **Slack capture specifics** (`ingest-thought` function code, Slack OAuth scopes beyond the `message.groups` event subscription, signing-secret handling, the Step 9 test message) live in `integrations/slack-capture/` — not among digested sources.
5. **Companion prompts full texts** (`docs/02-companion-prompts.md`) referenced but not digested.
6. **`fetch`/`search` alias response format** (how citation URLs are constructed from `OPEN_BRAIN_CITATION_BASE_URL`, ID scheme) is implementation detail in `server/index.ts`.
7. Whether `capture_thought` calls `upsert_thought` (dedup path) vs. plain INSERT is implied ("prevents duplicate thoughts... merges the metadata") but only verifiable in `server/index.ts`.
8. The credential-tracker xlsx column layout is only known functionally (auto-generates URLs in a "Step 6 section", client values in "Step 7 section"); the file itself was not digested.
