# Digest: OB1 `server/` (core Open Brain MCP server) + `primitives/` (reusable concept guides)

Source: `natebjones/OB1` repo snapshot — directories `server/` and `primitives/`.
Author: Nate B. Jones (GitHub `NateBJones`, repo org `NateBJones-Projects/OB1`).
This digest is written to be sufficient to rebuild the system without the originals.

---

## 1. server/ — the core "open-brain" MCP server

### 1.1 Files

| File | Purpose |
|---|---|
| `index.ts` | The entire MCP server — a single-file Supabase Edge Function (Deno) |
| `deno.json` | Import map pinning npm deps for the Deno edge runtime |
| `package.json` | Node-side test harness only (`npm test` → `node test-stateless.mjs`) |
| `test-stateless.mjs` | Infra-free protocol test of the per-request/stateless pattern |

### 1.2 Stack and dependencies

`deno.json` import map (exact pins used in production edge function):

```json
{
  "imports": {
    "@hono/mcp": "npm:@hono/mcp@0.1.1",
    "@modelcontextprotocol/sdk": "npm:@modelcontextprotocol/sdk@1.24.3",
    "hono": "npm:hono@4.9.2",
    "zod": "npm:zod@4.1.13",
    "@supabase/supabase-js": "npm:@supabase/supabase-js@2.47.10"
  }
}
```

`index.ts` first line imports the Supabase edge runtime types: `import "jsr:@supabase/functions-js/edge-runtime.d.ts";`

Test-harness devDependencies (`package.json`, name `ob1-server-tests`, `"type": "module"`): `@hono/mcp ^0.1.5`, `@hono/node-server ^1.19.11`, `@modelcontextprotocol/sdk ^1.28.0`, `hono ^4.12.9`.

### 1.3 Environment variables / secrets

Read at module top-level with `Deno.env.get(...)!`:

- `SUPABASE_URL` — auto-injected by Supabase Edge runtime
- `SUPABASE_SERVICE_ROLE_KEY` — auto-injected; the server creates ONE module-level client with the **service role** key (`createClient(SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY)`), i.e. RLS is bypassed in the core server
- `OPENROUTER_API_KEY` — for embeddings + metadata extraction via OpenRouter
- `MCP_ACCESS_KEY` — the shared-secret access key checked on every request
- `OPEN_BRAIN_CITATION_BASE_URL` — optional; defaults to `https://openbrain.local/thoughts`; used to build citation URLs `<base>/<thought_id>` (trailing slash stripped)

Constant: `OPENROUTER_BASE = "https://openrouter.ai/api/v1"`.

### 1.4 AI helper functions

**`getEmbedding(text)`** → POST `${OPENROUTER_BASE}/embeddings` with body `{"model": "openai/text-embedding-3-small", "input": text}`, header `Authorization: Bearer ${OPENROUTER_API_KEY}`. Returns `d.data[0].embedding` (number[]). On non-OK: throws `OpenRouter embeddings failed: <status> <body>`.

**`extractMetadata(text)`** → POST `${OPENROUTER_BASE}/chat/completions` with `model: "openai/gpt-4o-mini"`, `response_format: { type: "json_object" }`. System prompt (verbatim):

```
Extract metadata from the user's captured thought. Return JSON with:
- "people": array of people mentioned (empty if none)
- "action_items": array of implied to-dos (empty if none)
- "dates_mentioned": array of dates YYYY-MM-DD (empty if none)
- "topics": array of 1-3 short topic tags (always at least one)
- "type": one of "observation", "task", "idea", "reference", "person_note"
Only extract what's explicitly there.
```

Fallback if JSON parse fails: `{ topics: ["uncategorized"], type: "observation" }`.

**Metadata vocabulary (fixed):** `type` ∈ {`observation`, `task`, `idea`, `reference`, `person_note`}; keys: `people[]`, `action_items[]`, `dates_mentioned[]` (YYYY-MM-DD), `topics[]` (1–3 tags, ≥1 always). Capture adds `source: "mcp"`.

**`thoughtTitle(content, createdAt)`** — first 80 chars of whitespace-collapsed content, prefixed `"<localeDate> - <firstLine>"`; if empty content, `"<datePrefix> thought"`; if no date, prefix `"Open Brain"`.

**`thoughtUrl(id)`** — `${CITATION_BASE_URL}/${id}`.

### 1.5 Database surface (inferred schema contract)

Table **`thoughts`** with at least columns: `id` (string/uuid PK), `content` (text), `metadata` (jsonb), `embedding` (vector — updated after upsert), `created_at`, `updated_at`.

Postgres RPCs the server calls (must exist in the DB; SQL lives elsewhere in the repo, likely a `schema.sql`):

1. **`match_thoughts(query_embedding, match_threshold, match_count, filter)`** — pgvector semantic search returning rows shaped like `{ id, content, metadata, similarity, created_at }` (the `ThoughtMatch` type). Called with `filter: {}`.
2. **`upsert_thought(p_content, p_payload)`** — payload is `{ metadata: {...} }`; returns object containing `id`. After upsert the server separately does `UPDATE thoughts SET embedding = <emb> WHERE id = <id>` via PostgREST.

### 1.6 MCP tools (5 registered, exact names)

`buildServer()` creates `new McpServer({ name: "open-brain", version: "1.0.0" })` and registers, in order:

**1. `search`** — title "Search Open Brain". **ChatGPT compatibility tool.** Comment in code: "ChatGPT compatibility: restricted connector surfaces, company knowledge, and deep research look for exact read-only `search` and `fetch` tool shapes." Annotation `readOnlyHint: true`. Input: `{ query: z.string() }`. Behavior: embed query → `match_thoughts` with threshold 0.5, count 10, filter {} → returns JSON text `{"results": [{id, title, url}]}` (title via `thoughtTitle`, url via `thoughtUrl`). Errors returned as `isError: true` text content.

**2. `fetch`** — title "Fetch Open Brain Thought". ChatGPT-compat read tool. `readOnlyHint: true`. Input: `{ id: z.string() }`. Selects `id, content, metadata, created_at, updated_at` from `thoughts` `.eq("id", id).single()`. Returns JSON text document `{id, title, text, url, metadata: {...metadata, created_at, updated_at}}`.

**3. `search_thoughts`** — title "Search Thoughts", `readOnlyHint: true`. Input: `{ query: string, limit?: number = 10, threshold?: number = 0.5 }`. Same `match_thoughts` RPC, human-readable output format per result:

```
--- Result N (XX.X% match) ---
Captured: <localeDate>
Type: <type|unknown>
Topics: a, b            (if any)
People: x, y            (if any)
Actions: p; q           (if any)

<content>
```

Header line: `Found N thought(s):`. Empty: `No thoughts found matching "<query>".`

**4. `list_thoughts`** — title "List Recent Thoughts", `readOnlyHint: true`. Input: `{ limit?=10, type?, topic?, person?, days? }`. Filters via PostgREST: `contains("metadata", {type})`, `contains("metadata", {topics:[topic]})`, `contains("metadata", {people:[person]})`, `gte("created_at", now-days)`. Ordered `created_at desc`. Output per item: `N. [date] (type - topics)\n   content`; header `N recent thought(s):`; empty → `No thoughts found.`

**5. `thought_stats`** — title "Thought Statistics", `readOnlyHint: true`, no input. Exact count via `select("*", { count: "exact", head: true })`; then loads all `metadata, created_at` and aggregates counts of types/topics/people (top 10 each, sorted desc). Output lines: `Total thoughts: N`, `Date range: <oldest> → <newest>`, blank, `Types:` + `  k: v` lines, then optional `Top topics:` and `People mentioned:` sections.

**6. `capture_thought`** — title "Capture Thought". The only write tool. Annotations: `readOnlyHint: false, openWorldHint: false, destructiveHint: false, idempotentHint: false`. Input: `{ content: z.string() }` described as "The thought to capture — a clear, standalone statement that will make sense when retrieved later by any AI". Flow: `Promise.all([getEmbedding, extractMetadata])` → RPC `upsert_thought(p_content, p_payload: { metadata: {...metadata, source: "mcp"} })` → update embedding on returned id. Confirmation string: `Captured as <type|thought>` + ` — topics` + ` | People: ...` + ` | Actions: ...`.

All tool handlers wrap in try/catch and return `{content:[{type:"text", text:"Error: ..."}], isError:true}` on failure — never throw to transport.

### 1.7 Transport, auth, CORS — the critical operational pattern

**Transport:** `StreamableHTTPTransport` from `@hono/mcp`, Streamable HTTP (JSON or SSE responses). Served via `Deno.serve(app.fetch)` from a Hono app.

**Stateless per-request pattern (the key fix):** for EVERY request, a **fresh** `McpServer` is built (`buildServer()`), a fresh transport is created, `await server.connect(transport)`, then `transport.handleRequest(c)`. The response's `mcp-session-id` header is **deleted** before returning (stateless: no session affinity, no singleton corruption across edge isolates). If transport returns nothing: `503`-style `c.json({ error: "No response from MCP transport" }, 500, corsHeaders)`.

**Auth:** access key accepted TWO ways: header `x-brain-key` (core server's header name — extensions use `x-access-key` instead) OR URL query param `?key=`. Compared with strict equality against `MCP_ACCESS_KEY`.

**Auth failure = JSON-RPC error envelope over HTTP 200, NOT bare 401.** Code comment rationale (verbatim essence): strict MCP hosts (Codex CLI, Claude Code) treat bare HTTP 4xx as transport-level failures and tear the connection down; wrapping in a JSON-RPC error keeps the connection alive so clients can recover. Details:

- `JSON_RPC_UNAUTHORIZED_CODE = -32001` (conventional "Unauthorized" in the JSON-RPC implementation-defined range -32099..-32000)
- `UNAUTHORIZED_MESSAGE = "Unauthorized: missing or invalid authentication."`
- Response body: `{"jsonrpc":"2.0","error":{"code":-32001,"message":...},"id":<echoed>}` at HTTP **200** with `Content-Type: application/json` + CORS headers.
- Best-effort id echo: `readBodyText()` (null for GET/HEAD/DELETE or read failure) → `extractJsonRpcId()` preserves string/number/null ids; anything else → `null`.
- Note: the standalone test file still asserts 401 (older pattern) — the production `index.ts` is the JSON-RPC-envelope version.

**Accept-header patch for Claude Desktop:** Claude Desktop connectors don't send the `Accept: text/event-stream` header that `StreamableHTTPTransport` requires. If `accept` doesn't include `text/event-stream`, the server rebuilds the Request with `Accept: application/json, text/event-stream` (with `duplex: "half"` for Deno streaming body, `@ts-ignore`d) and swaps it into `c.req.raw` via `Object.defineProperty`. Referenced issue: `https://github.com/NateBJones-Projects/OB1/issues/33`.

**CORS** (needed for browser/Electron clients — Claude Desktop, claude.ai):

```
Access-Control-Allow-Origin: *
Access-Control-Allow-Headers: authorization, x-client-info, apikey, content-type, x-brain-key, accept, mcp-session-id, mcp-protocol-version, last-event-id
Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE
```

`app.options("*")` answers preflight with `ok`/200 + CORS headers. CORS headers are also re-applied onto every transport response.

### 1.8 test-stateless.mjs — verification steps

Purpose: "Validates the per-request McpServer pattern without any infra (no Supabase, no DB). MCP initialize is a pure protocol handshake." Run: `npm install; node test-stateless.mjs` (or `npm test`) from `server/`.

Mirrors the pattern with a `ping` tool, test key `test-key-xyz`, Hono on `@hono/node-server` at a random port. Helper `readMcpBody` handles both raw JSON and SSE (`data: ` line) responses. Initialize body uses `protocolVersion: "2024-11-05"`.

Six test groups (assertions):
1. CORS preflight — OPTIONS→200, origin `*`, methods header present
2. Auth rejection wrong key → 401 with CORS (note: test uses older bare-401 pattern)
3. Auth rejection missing key → 401
4. MCP initialize → 200, **no `mcp-session-id` header** (stateless), CORS on success, body has `result.protocolVersion` and `result.capabilities`
5. Per-request isolation — two sequential initializes both succeed ("no singleton corruption")
6. `tools/list` → 200, no session header, parseable body

Exit code 1 with `FAIL` if any assertion fails, else `PASS`.

---

## 2. primitives/ — reusable concept guides

Top-level `primitives/README.md`: "Primitives are reusable concept guides that show up in multiple extensions. Learn them once, apply them everywhere." Curated: a primitive should be referenced by ≥2 extensions to justify extraction; proposals via GitHub issue template `primitive-submission.yml`. Extensions link to primitives on first use of a concept — users read them when an extension says to.

Index table (exact):

| Primitive | What It Teaches | Used By |
|---|---|---|
| Deploy an Edge Function | Deploying any extension as a Supabase Edge Function | All extensions |
| Remote MCP Connection | Connecting to Claude Desktop, ChatGPT, Claude Code, Cursor, and other clients | All extensions |
| Common Troubleshooting | Solutions for connection, deployment, and database issues | All extensions |
| Row Level Security | PostgreSQL policies for multi-user data isolation | Extensions 4, 5, 6 |
| Shared MCP Server | Giving others scoped access to parts of your brain | Extension 4 |

The six extensions referenced throughout: 1 Household Knowledge Base (`extensions/household-knowledge/`), 2 Home Maintenance Tracker (`home-maintenance`), 3 Family Calendar (`family-calendar`), 4 Meal Planning (`meal-planning`), 5 Professional CRM (`professional-crm`), 6 Job Hunt Pipeline (`job-hunt`).

### 2.1 `_template/` — primitive authoring format

`_template/README.md` section skeleton (headings, in order): `# Primitive Name` → blockquote one-liner → `## What It Is` → `## Why It Matters` → `## How It Works` → `## Common Patterns` (`### Pattern 1: [Name]` with sql/code block, `### Pattern 2: [Name]`) → `## Step-by-Step Guide` (numbered) → `## Expected Outcome` → `## Troubleshooting` (`**Issue: [Common problem]**` / `Solution: ...` pairs) → `## Extensions That Use This` (links `../../extensions/extension-slug/`) → `## Further Reading`.

`_template/metadata.json` schema (exact keys):

```json
{
  "name": "Primitive Name",
  "description": "Brief description of this reusable concept.",
  "category": "primitives",
  "author": { "name": "Your Name", "github": "your-github-username" },
  "version": "1.0.0",
  "requires": { "open_brain": true, "services": [], "tools": [] },
  "tags": ["tag1", "tag2"],
  "difficulty": "intermediate",
  "estimated_time": "20 minutes"
}
```

Real metadata files add optional `"created"` / `"updated"` dates (`"2026-03-13"` on deploy-edge-function, remote-mcp, troubleshooting; absent on rls and shared-mcp). Difficulty vocabulary observed: `beginner` (deploy 10 min, remote-mcp 5 min, troubleshooting 5 min), `intermediate` (rls 20 min, shared-mcp 30 min).

### 2.2 deploy-edge-function primitive — the deployment pattern

The canonical deployment of any extension (and the core server) as a Supabase Edge Function. Prerequisites (from a "Getting Started Guide" `docs/01-getting-started.md`): Supabase CLI installed; a project folder where `supabase init` and `supabase link` are already done; a "credential tracker" holding project ref + secrets. OS-specific commands are marked 🟩 Mac/Linux vs 🟦 Windows PowerShell.

Every extension README carries a deployment table with `Function name` (e.g. `extension-name-mcp` — the `-mcp` suffix convention, example `household-knowledge-mcp`) and `Download path` (e.g. `extensions/extension-name`). Tokens to substitute: `FUNCTION_NAME`, `DOWNLOAD_PATH`, `YOUR_PROJECT_REF`.

**Steps:**

1. `supabase functions new FUNCTION_NAME`
2. Download the two server files into the function directory (not project root):
   - `curl -o supabase/functions/FUNCTION_NAME/index.ts https://raw.githubusercontent.com/NateBJones-Projects/OB1/main/DOWNLOAD_PATH/index.ts`
   - same for `deno.json`
   - Windows: `Invoke-WebRequest -Uri ... -OutFile supabase\functions\FUNCTION_NAME\index.ts`
3. Generate access key (64 hex chars): `openssl rand -hex 32` / PowerShell `-join ((1..32) | ForEach-Object { '{0:x2}' -f (Get-Random -Maximum 256) })`. Save in credential tracker. Set: `supabase secrets set MCP_ACCESS_KEY=your-generated-key-here`. Reuse across extensions is allowed. **Warning:** secrets are shared by ALL functions in the project — re-setting `MCP_ACCESS_KEY` rotates it for every deployed function; for per-extension keys use a different secret name (e.g. `HOUSEHOLD_MCP_KEY`) and edit the extension's `index.ts` to read it.
4. Deploy: `supabase functions deploy FUNCTION_NAME --no-verify-jwt` (**`--no-verify-jwt` is required for MCP** — the server does its own key auth; without it you get "Invalid JWT").

Live URL: `https://YOUR_PROJECT_REF.supabase.co/functions/v1/FUNCTION_NAME`.
**MCP Connection URL** (the term of art): `https://YOUR_PROJECT_REF.supabase.co/functions/v1/FUNCTION_NAME?key=your-access-key`.

**Updating:** re-download `index.ts` (curl/Invoke-WebRequest same command) then redeploy with the same command; URL and key are unchanged → no client reconfiguration.

Troubleshooting highlights: "Function not found" → run `functions new` first / wrong folder; import-map errors → `deno.json` must be in the function directory (`ls supabase/functions/FUNCTION_NAME/` should show both files); runtime errors → Dashboard → Edge Functions → function → Logs, `supabase secrets list` should show `MCP_ACCESS_KEY`, `SUPABASE_URL`/`SUPABASE_SERVICE_ROLE_KEY` are auto-injected; "Invalid JWT" → forgot `--no-verify-jwt`.

### 2.3 remote-mcp primitive — client connection pattern

Prerequisite: the **MCP Connection URL** `https://YOUR_REF.supabase.co/functions/v1/extension-mcp?key=your-access-key`.

**Claude Desktop:** Settings → Connectors → **Add custom connector** → Name = extension name → Remote MCP server URL = MCP Connection URL → Add. Then per-conversation: "+" button at bottom of chat → Connectors → enable toggle. Multiple extensions = separate connectors toggled per conversation.

**ChatGPT:** requires paid plan (Plus, Pro, Business, Enterprise, Edu); web only at chatgpt.com, not mobile. Caveat note (as of May 2026): custom MCP is beta, plan- and model-sensitive; Developer Mode documented for Plus/Pro/Business/Enterprise/Edu; workspace app publishing/action controls mainly Business/Enterprise/Edu. **Mark read-only tools with the MCP `readOnlyHint` annotation and expose exact `search`/`fetch` read tools** for restricted ChatGPT / company-knowledge surfaces (this is why the core server registers `search` + `fetch`). One-time: profile → Settings → **Apps & Connectors** → **Advanced settings** → toggle **Developer mode** ON (this disables ChatGPT's built-in Memory — Open Brain replaces it). Add: Settings → Apps & Connectors → **Create** → name, description, MCP endpoint URL = Connection URL, Authentication = **No Authentication** (key is embedded in URL) → Create. Usage tip: ChatGPT often needs explicit tool references ("Use the search_household_items tool to find my paint colors"). If server logs show zero requests, the call never left ChatGPT — refresh/recreate the app, fresh chat, select the app in Developer Mode, try a thinking model; on restricted Pro sessions, `search`/`fetch` read tools appear more reliably than write tools.

**Claude Code:**

```bash
claude mcp add --transport http extension-name \
  https://YOUR_PROJECT_REF.supabase.co/functions/v1/extension-mcp \
  --header "x-access-key: your-access-key"
```

URL WITHOUT `?key=`; key goes in the header. Header-name gotcha: **core Open Brain server expects `x-brain-key`; extension servers expect `x-access-key`** — the docs recommend preferring `?key=` to avoid confusion.

**Cursor:** native remote support; `~/.cursor/mcp.json`:

```json
{ "mcpServers": { "extension-name": { "url": "https://YOUR_PROJECT_REF.supabase.co/functions/v1/extension-mcp?key=your-access-key" } } }
```

Restart Cursor; tools appear in Settings → Features → MCP. **Do NOT use `mcp-remote` for Cursor** — newer `mcp-remote` attempts OAuth client registration which fails against simple key auth.

**Other clients (Windsurf, VS Code, Zed):** Option A — URL-with-`?key=` field if the client supports remote MCP. Option B — stdio-only clients bridge via `mcp-remote` (requires Node.js): `{"command":"npx","args":["-y","mcp-remote","https://...?key=..."]}`; pass the key via **query param, not `--header`** (mcp-remote@latest does OAuth discovery before custom headers, breaking header auth).

Troubleshooting: connector toggle per conversation; URL must end `?key=...`; remove/re-add connector; 401 = key mismatch with Supabase secret; cold-start latency on first Edge Function request is normal; pick nearest Supabase region.

### 2.4 rls primitive — multi-user isolation (Postgres RLS on Supabase)

Concept: policies live in the database so security holds regardless of access path (MCP, REST, SQL). Supabase conventions: (1) auth context from JWT → `auth.uid()`; (2) **policies are additive** — OR'd, row visible if ANY policy allows; (3) **`service_role` key bypasses RLS entirely**; (4) four policy types: SELECT / INSERT / UPDATE / DELETE.

**Pattern 1: User-Scoped** (personal data):

```sql
ALTER TABLE personal_notes ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Users can view their own notes" ON personal_notes FOR SELECT USING (auth.uid() = user_id);
CREATE POLICY "Users can insert their own notes" ON personal_notes FOR INSERT WITH CHECK (auth.uid() = user_id);
CREATE POLICY "Users can update their own notes" ON personal_notes FOR UPDATE USING (auth.uid() = user_id) WITH CHECK (auth.uid() = user_id);
CREATE POLICY "Users can delete their own notes" ON personal_notes FOR DELETE USING (auth.uid() = user_id);
```

**Pattern 2: Team/Household-Scoped** (shared access — the TEAM-sharing-relevant pattern). Schema:

```sql
CREATE TABLE households (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name TEXT NOT NULL,
  created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE TABLE household_members (
  household_id UUID REFERENCES households(id) ON DELETE CASCADE,
  user_id UUID REFERENCES auth.users(id) ON DELETE CASCADE,
  role TEXT DEFAULT 'member',
  PRIMARY KEY (household_id, user_id)
);
CREATE TABLE shared_shopping_lists (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  household_id UUID REFERENCES households(id) ON DELETE CASCADE,
  item_name TEXT NOT NULL,
  quantity INTEGER,
  created_by UUID REFERENCES auth.users(id),
  created_at TIMESTAMPTZ DEFAULT NOW()
);
```

Enable RLS on all three tables. Membership-subquery policy shape (applied FOR SELECT/INSERT/UPDATE/DELETE on the shared table):

```sql
CREATE POLICY "Household members can view shared shopping lists"
  ON shared_shopping_lists FOR SELECT
  USING (household_id IN (SELECT household_id FROM household_members WHERE user_id = auth.uid()));
-- INSERT uses WITH CHECK (same subquery); UPDATE/DELETE use USING (same subquery)
```

Plus self-visibility policies: `household_members` FOR SELECT `USING (user_id = auth.uid())`; `households` FOR SELECT `USING (id IN (SELECT household_id FROM household_members WHERE user_id = auth.uid()))`.

**Pattern 3: Public + Private** — table has `visibility TEXT DEFAULT 'private' CHECK (visibility IN ('public','private'))`; SELECT policy `USING (visibility = 'public' OR auth.uid() = user_id)`; write policies owner-only as Pattern 1.

**Step-by-step:** Supabase SQL Editor → New query → `ALTER TABLE ... ENABLE ROW LEVEL SECURITY;` → create SELECT policy → other policies → test with `supabase-js` using a **user JWT, not service role** → verify:

```sql
SELECT schemaname, tablename, rowsecurity FROM pg_tables WHERE tablename = 'your_table_name';  -- rowsecurity must be true
SELECT * FROM pg_policies WHERE tablename = 'your_table_name';  -- policies exist
SELECT auth.uid();  -- auth context sanity check
```

**Troubleshooting:** RLS on + no policies = no rows visible (create at least SELECT). `service_role` bypasses RLS by design — use `anon`/user JWTs when RLS should apply. **Key admission re MCP servers:** "MCP servers often use the `service_role` key, which bypasses RLS" — options: accept the bypass, refactor to user tokens (JWT in `Authorization` header), or "implement user-scoped RLS even with `service_role` by adding a `user_id` parameter to your queries and filtering explicitly" (application-level scoping). Used by extensions 4 (meal-planning: recipes/meal_plans/shopping_lists), 5 (professional-crm: contacts/interactions/opportunities), 6 (job-hunt: entire 5-table schema).

Further reading links: Supabase RLS guide, PostgreSQL CREATE POLICY docs, Supabase Auth Helpers, Supabase blog "postgres-rls-performance".

### 2.5 shared-mcp primitive — scoped access for other people (TEAM sharing)

Problem statement: share PART of your brain (spouse → meal plans/shopping; collaborator → project tasks not personal notes; team member → read-only docs) without giving your main MCP server or full DB access. Answer: a **separate MCP server with scoped credentials, limited table access, controlled permissions**.

**Three-layer security model:**
1. **Scoped credentials** — separate DB role/API key; only specific tables; revocable independently of the main server.
2. **Limited table access** — RLS policies + table-level GRANTs; sensitive tables hidden entirely.
3. **Read-only vs read-write per table** — e.g. SELECT-only recipes, SELECT/INSERT/UPDATE shopping items, DELETE blocked entirely.

**Step 1 — sharing decision map** (be explicit; "Default to not sharing unless there's a clear reason"):

```
Table: meal_plans           — SELECT              — spouse views planned meals
Table: recipes              — SELECT              — spouse views recipes
Table: shopping_list_items  — SELECT, INSERT, UPDATE — view/add/check off items
Table: thoughts (NOT SHARED); contacts (NOT SHARED); work_projects (NOT SHARED)
```

**Step 2 — scoped Postgres role:**

```sql
CREATE ROLE household_member LOGIN PASSWORD 'secure_password_here';
GRANT CONNECT ON DATABASE postgres TO household_member;
GRANT USAGE ON SCHEMA public TO household_member;
GRANT SELECT ON public.meal_plans TO household_member;
GRANT SELECT ON public.recipes TO household_member;
GRANT SELECT, INSERT, UPDATE ON public.shopping_list_items TO household_member;
ALTER TABLE shopping_list_items ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Household members see shared lists" ON shopping_list_items FOR SELECT TO household_member
  USING (household_id = current_setting('app.current_household')::uuid);
CREATE POLICY "Household members update shared lists" ON shopping_list_items FOR UPDATE TO household_member
  USING (household_id = current_setting('app.current_household')::uuid);
```

Note the `current_setting('app.current_household')::uuid` session-variable technique for household scoping with a role-based (non-`auth.uid()`) connection. "For Supabase specifically: create a service role key with restricted permissions through the dashboard, or use connection pooling with different credentials."

**Step 3 — separate Edge Function server** (same Hono + MCP SDK + StreamableHTTPTransport stack). Distinctive choices vs the core server:
- Route is `app.post("/mcp", ...)` (plus `app.get("/", ...)` health returning `{ status: "ok", service: "Household Shared", version: "1.0.0" }`) — not `app.all("*")`.
- Auth: `c.req.query("key") || c.req.header("x-access-key")` vs `Deno.env.get("MCP_HOUSEHOLD_ACCESS_KEY")`; failure → bare `401` `{error:"Unauthorized"}` (this guide predates the JSON-RPC-envelope fix).
- Supabase client created **per request** with `SUPABASE_HOUSEHOLD_KEY` (the LIMITED key), never the service role.
- Server name `household-shared-server` v1.0.0; per-request McpServer + transport, `transport.handleRequest(c)`.
- Tools (older `server.tool(name, description, schema, handler)` API): `view_meal_plans` (`days?: number`, selects meal_plans from today asc, limit days||7), `view_shopping_list` (unpurchased items desc), `add_shopping_item` (`item`, `quantity?` default "1", inserts purchased:false), `update_shopping_item` (`id`, `purchased: boolean`). No delete tool exposed.

**Step 4 — separate secrets:** `openssl rand -hex 32`; `supabase secrets set MCP_HOUSEHOLD_ACCESS_KEY=...`; `supabase secrets set SUPABASE_HOUSEHOLD_KEY=...` (limited key); optional `SHARED_HOUSEHOLD_ID=uuid-here` for RLS. (A `package.json` for a Node build variant is also shown: name `household-shared-server`, scripts `build: tsc`, `start: node --env-file=.env.shared dist/shared-server.js`, deps `@modelcontextprotocol/sdk ^0.5.0`, `@supabase/supabase-js ^2.39.0` — legacy local-deployment remnant.)

**Step 5 — deploy:** `supabase functions new household-shared-mcp` → copy code → set the two secrets → `supabase functions deploy household-shared-mcp --no-verify-jwt`. The other person adds a Claude Desktop custom connector named `Household Shared` with URL `https://YOUR_PROJECT_REF.supabase.co/functions/v1/household-shared-mcp?key=shared-access-key`. Key points: they connect via URL only (no Node/config/terminal), never see your main server credentials, and **revocation = rotate the shared access key in Supabase secrets**.

**Step 6 — boundary test** (script `test-boundaries.ts` using the shared client): meal_plans SELECT → ALLOWED; shopping_list_items SELECT → ALLOWED; `thoughts` SELECT → must be BLOCKED; shopping_list_items DELETE → must be BLOCKED. Expected output verbatim:

```
meal_plans: ALLOWED
shopping_list_items (SELECT): ALLOWED
thoughts: BLOCKED ✓
shopping_list_items (DELETE): BLOCKED ✓
```

Troubleshooting: "Permission denied for table X" → inspect `information_schema.role_table_grants WHERE grantee='household_member'` and add grants; over-broad access → `REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM household_member;` then re-grant minimal set; changes not syncing → confirm same `SUPABASE_URL` on both sides, inspect `pg_tables.rowsecurity` and `pg_policies`, optionally `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` if not needed; server won't start (legacy local variant) → Node 18+, run manually with `--env-file`, check `~/Library/Logs/Claude/mcp*.log`, verify `?key=` matches `MCP_HOUSEHOLD_ACCESS_KEY` and `supabase functions list` shows the function.

Operational next steps: audit shared scope regularly, monitor/log shared-server queries, start read-only and widen as trust builds, write a user guide for the other person.

### 2.6 troubleshooting primitive — cross-extension issue catalogue

Connection: paused Supabase projects need restoring; `SUPABASE_URL`/`SUPABASE_SERVICE_ROLE_KEY` auto-injected in Edge Functions; 401 = key mismatch (check `?key=`, remember `x-brain-key` core vs `x-access-key` extensions, prefer `?key=`; `supabase secrets list`; rotate with `openssl rand -hex 32` + `supabase secrets set MCP_ACCESS_KEY=new-key` + update Connection URL); Claude Desktop tools missing → enable per-conversation toggle, re-add connector, new conversation, restart app; ChatGPT → Developer Mode + explicit tool references.

Deployment: `supabase --version`; `supabase link --project-ref YOUR_PROJECT_REF`; function dir exists; `deno.json` in function dir; `--no-verify-jwt` required; logs via Dashboard or `supabase functions logs your-function-name`.

Database: "relation does not exist" → extension's `schema.sql` not run / pgvector missing / statements out of order — re-run in SQL Editor; "permission denied"/RLS → verify service role key (not the publishable/anon key), RLS policies created by schema.sql for Extensions 4–6, `user_id` values are valid UUIDs; FK violations → create parent first (e.g. company before job posting), same `user_id`, copy-paste UUIDs.

Performance: cold-start warmup normal; nearest region; empty search → add data first, broaden terms (**most search tools use ILIKE**), check date filters, for semantic search "search with threshold 0.3" widens the net.

Data: dates in ISO 8601 (`YYYY-MM-DD` / `YYYY-MM-DDTHH:MM:SSZ`); let tools compute "N days from now"; auto-calculated fields need DB triggers from schema.sql; null frequency (one-time tasks) leaves auto-fields null by design.

Help channels: Supabase dashboard AI assistant (bottom-right chat icon); OB1 Discord `https://discord.gg/Cgh9WJEkeG`, `#help` channel.

---

## 3. Cross-cutting conventions worth preserving

- **One extension = one single-file Edge Function** (`index.ts` + `deno.json`), function name `<extension>-mcp`, deployed `--no-verify-jwt`, self-authenticating via shared-secret key.
- **Stateless per-request MCP**: fresh `McpServer` + `StreamableHTTPTransport` per request; strip `mcp-session-id` from responses. This is load-bearing on Supabase's isolate model (avoids singleton corruption).
- **Two auth carriage options**: `?key=` query param (universal, recommended) or header (`x-brain-key` core / `x-access-key` extensions).
- **Auth failures as JSON-RPC -32001 over HTTP 200** for strict hosts (Codex CLI, Claude Code) — the newest pattern, only in the core `index.ts`.
- **Accept-header patch** for Claude Desktop's missing `text/event-stream`.
- **ChatGPT compat**: register exact `search`/`fetch` read-only tools with `readOnlyHint: true` returning `{results:[{id,title,url}]}` and a `{id,title,text,url,metadata}` document respectively.
- **Sharing model**: never hand out the main key; a second Edge Function + second access key + limited Supabase key/DB role = the entire tenancy boundary; revoke by rotating the shared secret.
- **RLS caveat**: the core server runs on `service_role` and BYPASSES RLS; RLS patterns apply to extensions 4–6 and to user-JWT paths; explicit `user_id` filtering is the documented fallback when staying on service_role.
- **Credential tracker**: a user-maintained document holding project ref, access keys, Connection URLs — referenced by all guides.
- Doc conventions: 🟩 Mac/Linux vs 🟦 Windows (PowerShell) command blocks; per-primitive `metadata.json` with `category: "primitives"`, difficulty ∈ {beginner, intermediate}, `estimated_time`, `requires.open_brain: true`.

---

## OPEN QUESTIONS

1. **`schema.sql` for the core `thoughts` table is not in these two directories** — the exact DDL for `thoughts`, the pgvector index, and the `match_thoughts` / `upsert_thought` function bodies live elsewhere in the repo (likely `docs/01-getting-started.md` or a root `schema.sql`). Their signatures are inferable from call sites (documented above) but the exact SQL is not captured here.
2. **`upsert_thought` semantics**: it is named "upsert" and marked `idempotentHint: false` — the dedupe/conflict key (content hash? exact content match?) is defined in the missing SQL.
3. **Embedding dimensionality** (text-embedding-3-small → presumably 1536) and the pgvector distance operator used by `match_thoughts` are in the missing schema.
4. **Test vs production auth mismatch**: `test-stateless.mjs` asserts bare 401s while production `index.ts` returns JSON-RPC 200 envelopes — the test predates the fix and would need updating to test the current behavior.
5. **`Object.defineProperty(c.req, "raw", ...)`** relies on Hono internals; version pinned at hono@4.9.2 in prod vs ^4.12.9 in tests — compatibility across Hono versions unverified.
6. **shared-mcp guide inconsistencies** (legacy残): Step 4 shows a Node `package.json`/local `dist/shared-server.js` deployment and troubleshooting for a spouse-laptop install, while Steps 3/5 are Edge Function based; also the "Concrete Example" says "Compiled MCP server on spouse's laptop". The Edge Function path is the current one. Its 401-on-auth-failure and `app.post("/mcp")` route also diverge from the core server's `app.all("*")` + JSON-RPC-envelope pattern.
7. **Whether the shared server's SQL role (`household_member`) actually connects from an Edge Function** is unspecified — supabase-js with a "limited key" (custom JWT? restricted anon key?) is hand-waved ("create a service role key with restricted permissions through the Supabase dashboard"); Supabase does not natively offer per-role service keys, so the concrete mechanism for `SUPABASE_HOUSEHOLD_KEY` is ambiguous.
8. **`OPEN_BRAIN_CITATION_BASE_URL`** default `https://openbrain.local/thoughts` is a non-resolvable placeholder — whether any real UI serves those citation URLs is unknown from these files.
9. The referenced **extensions/ and docs/ directories** (deployment tables, per-extension schemas, Getting Started steps 1–6) were not part of this digest's sources.
