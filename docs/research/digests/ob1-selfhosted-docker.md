# DIGEST: OB1 (Open Brain) — Fully Self-Hosted Deployment, Dashboard Docker Support, and Chat Capture Bots

Sources digested (from Nate B. Jones' OB1 repo snapshot, local scratchpad copy):
- `OB1/integrations/kubernetes-deployment/` (README.md, Dockerfile, deno.json, index.ts, k8s/init.sql, k8s/openbrain.yml, k8s/secrets.yml.example, metadata.json)
- `OB1/dashboards/open-brain-dashboard-next/` (Next.js dashboard — full source)
- `OB1/dashboards/open-brain-dashboard/` (SvelteKit dashboard — full source)
- `OB1/integrations/slack-capture/` (README.md with full Edge Function source inline, metadata.json)
- `OB1/integrations/discord-capture/` (README.md stub, metadata.json)

Purpose of this digest: enable a rebuild of a fully self-hosted Open Brain (PostgreSQL + pgvector, NO Supabase), with dashboards and capture bots (to be replaced by Nextcloud Talk), without access to the originals.

---

## 1. KUBERNETES SELF-HOSTED DEPLOYMENT (`integrations/kubernetes-deployment`)

Community contribution by **@velo** ("Marvin"), version 1.0.0, created/updated 2026-03-22, difficulty "advanced", estimated_time "60 minutes". Tested on K3s v1.31; works on any K8s distribution.

**What it replaces:** the standard OB1 stack is Supabase (Postgres + pgvector + Edge Functions) + OpenRouter. This integration replaces Supabase entirely with:
1. A plain PostgreSQL + pgvector container (`ankane/pgvector:v0.5.1`).
2. A modified Deno MCP server (`index.ts`) that talks raw SQL to Postgres instead of using the Supabase client/RPC. "All MCP tools and the Hono HTTP layer are preserved; only the data access layer is changed."

The MCP endpoint is a **remote HTTP endpoint** (Streamable HTTP transport), reachable by URL from any MCP client, served via K8s Service and optionally Ingress.

### 1.1 Prerequisites
- Working Kubernetes cluster; `kubectl`; Docker (to build the MCP image)
- An embedding/chat API provider: OpenRouter, OpenAI, or any local model with an OpenAI-compatible API (Ollama, BitNet, llama.cpp)
- Ingress controller (Traefik, nginx-ingress, etc.) only for external access

### 1.2 Credential tracker (verbatim fields)
```
POSTGRESQL:  Password
MCP SERVER:  Access key
EMBEDDING/CHAT API:  API base URL, API key, Embedding model, Chat model
```

### 1.3 MCP server Docker image (Dockerfile, verbatim behavior)
```dockerfile
FROM denoland/deno:2.3.3
WORKDIR /app
COPY deno.json ./
RUN deno install
COPY index.ts ./
USER deno
EXPOSE 8000
CMD ["deno", "run", "--allow-net", "--allow-env", "--allow-read", "index.ts"]
```

`deno.json` dependency pins:
```json
{
  "imports": {
    "@hono/mcp": "npm:@hono/mcp@0.1.1",
    "@modelcontextprotocol/sdk": "npm:@modelcontextprotocol/sdk@1.24.3",
    "hono": "npm:hono@4.9.2",
    "zod": "npm:zod@4.1.13",
    "postgres": "https://deno.land/x/postgres@v0.19.3/mod.ts"
  }
}
```

Build/import commands:
```bash
docker build -t openbrain-mcp-server:latest .
# K3s:      docker save openbrain-mcp-server:latest | sudo k3s ctr images import -
# minikube: minikube image load openbrain-mcp-server:latest
# other:    docker tag ... your-registry/openbrain-mcp-server:latest && docker push ...
```

### 1.4 Kubernetes objects (k8s/openbrain.yml)

All in namespace **`openbrain`**. One StatefulSet pod (`openbrain-0`) with **two containers sharing the network namespace (communicate via 127.0.0.1)**.

**Namespace** `openbrain`.

**ConfigMap** `openbrain-init-sql`, key `init.sql` (identical content to standalone `k8s/init.sql`, see §1.5). Mounted into the db container at `/docker-entrypoint-initdb.d/init.sql` (subPath `init.sql`) so Postgres runs it on first initialization only.

**Secret** `openbrain-secret` (type Opaque, stringData; from `secrets.yml.example`, copy to `secrets.yml`, never commit):
| key | meaning |
|---|---|
| `postgres-password` | PostgreSQL password |
| `mcp-access-key` | MCP client auth key (clients send it) |
| `embedding-api-key` | Embedding API key (OpenRouter/OpenAI/local proxy) |
| `chat-api-key` | Chat API key (same as embedding key if same provider) |

**StatefulSet** `openbrain` — `serviceName: "openbrain"`, `replicas: 1`, selector/labels `app: openbrain`.

Container 1 — **`db`** (`ankane/pgvector:v0.5.1`):
- env: `POSTGRES_USER=postgres`, `POSTGRES_PASSWORD` ← secret `postgres-password`, `POSTGRES_DB=openbrain`
- port 5432; readinessProbe tcpSocket:5432, initialDelaySeconds 10, periodSeconds 10
- resources: requests cpu 250m / mem 256Mi; limits mem 1Gi
- volumeMounts: `db-data` → `/var/lib/postgresql/data`; `init-sql` ConfigMap → `/docker-entrypoint-initdb.d/init.sql`

Container 2 — **`mcp-server`** (`openbrain-mcp-server:latest`, imagePullPolicy IfNotPresent):
- port 8000 (named `http`); readinessProbe tcpSocket:8000, 10/10
- resources: requests cpu 100m / mem 128Mi; limits mem 512Mi
- env (exact values as shipped):
  - `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_NAME=openbrain`, `DB_USER=postgres`, `DB_PASSWORD` ← secret
  - `MCP_ACCESS_KEY` ← secret `mcp-access-key`
  - `EMBEDDING_API_BASE=https://openrouter.ai/api/v1`, `EMBEDDING_API_KEY` ← secret `embedding-api-key`, `EMBEDDING_MODEL=openai/text-embedding-3-small`
  - `CHAT_API_BASE=https://openrouter.ai/api/v1`, `CHAT_API_KEY` ← secret `chat-api-key`, `CHAT_MODEL=openai/gpt-4o-mini`
  - `PORT=8000`

Volumes:
- `db-data`: **hostPath** `/var/openbrain/db`, type `DirectoryOrCreate` (NOT a PVC — single-node assumption)
- `init-sql`: configMap `openbrain-init-sql`

**Service** `openbrain` — ClusterIP, port 8000 → targetPort `http`, port name `mcp`.

**Ingress** — commented-out template: `ingressClassName: nginx` (or traefik), TLS host `brain.yourdomain.com` with secret `brain-tls`, path `/` Prefix → service `openbrain:8000`.

Deploy order:
```bash
kubectl apply -f k8s/secrets.yml
kubectl apply -f k8s/openbrain.yml
```

### 1.5 Database schema (init.sql — the complete standalone schema, replaces Supabase-managed schema)

```sql
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS thoughts (
    id BIGSERIAL PRIMARY KEY,
    content TEXT NOT NULL,
    embedding vector(1536),
    metadata JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_thoughts_created_at ON thoughts (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_thoughts_metadata ON thoughts USING GIN (metadata);

-- match_thoughts replaces the Supabase RPC function
CREATE OR REPLACE FUNCTION match_thoughts(
    query_embedding vector(1536),
    match_threshold FLOAT DEFAULT 0.5,
    match_count INT DEFAULT 10,
    filter JSONB DEFAULT '{}'::jsonb
)
RETURNS TABLE (
    id BIGINT,
    content TEXT,
    metadata JSONB,
    similarity FLOAT,
    created_at TIMESTAMP WITH TIME ZONE
)
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN QUERY
    SELECT
        t.id, t.content, t.metadata,
        (1 - (t.embedding <=> query_embedding))::FLOAT AS similarity,
        t.created_at
    FROM thoughts t
    WHERE 1 - (t.embedding <=> query_embedding) >= match_threshold
    ORDER BY t.embedding <=> query_embedding
    LIMIT match_count;
END;
$$;
```

Notes:
- Similarity metric: **cosine** (`<=>` operator), similarity = 1 − cosine distance, default threshold **0.5**, default count **10**.
- Embedding dimension **1536** (matches `text-embedding-3-small`). If your model differs, change `vector(1536)` in the init SQL; on an existing DB you must drop and recreate `thoughts`.
- The `filter JSONB` parameter is declared but unused in the function body.
- Init SQL runs **only on first Postgres startup** (docker-entrypoint-initdb.d semantics). To re-initialize: delete the data volume dir and `kubectl delete pod openbrain-0 -n openbrain` (StatefulSet recreates).
- IMPORTANT delta vs production OB1: here `thoughts.id` is `BIGSERIAL` (integer); the production Supabase schema (per the Next dashboard README) uses **UUID string ids**. The minimal K8s schema also lacks columns the REST dashboard expects (`type`, `source_type`, `importance`, `quality_score`, `sensitivity_tier`, `status`, `status_updated_at`, `updated_at`); type/topics/people live only in the `metadata` JSONB in this schema.

### 1.6 MCP server behavior (index.ts — complete operational spec)

Env vars read (with defaults):
| var | default |
|---|---|
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `5432` |
| `DB_NAME` | `openbrain` |
| `DB_USER` | `postgres` |
| `DB_PASSWORD` | (required, `!`) |
| `EMBEDDING_API_BASE` | `https://openrouter.ai/api/v1` |
| `EMBEDDING_API_KEY` | falls back to `OPENROUTER_API_KEY`, else `""` |
| `EMBEDDING_MODEL` | `openai/text-embedding-3-small` |
| `CHAT_API_BASE` | defaults to `EMBEDDING_API_BASE` |
| `CHAT_API_KEY` | defaults to `EMBEDDING_API_KEY` |
| `CHAT_MODEL` | `openai/gpt-4o-mini` |
| `MCP_ACCESS_KEY` | (required, `!`) |
| `OPEN_BRAIN_CITATION_BASE_URL` | `https://openbrain.local/thoughts` |
| `PORT` | `8000` |

- Postgres pool: deno-postgres `Pool(..., 20)` (20 connections).
- Embeddings: `POST {EMBEDDING_API_BASE}/embeddings` with `{model, input}`, Bearer auth; returns `d.data[0].embedding`. Throws `Embedding API failed: <status> <body>` on non-OK.
- Metadata extraction: `POST {CHAT_API_BASE}/chat/completions`, `response_format: {type:"json_object"}`, system prompt **verbatim**:

```
Extract metadata from the user's captured thought. Return JSON with:
- "people": array of people mentioned (empty if none)
- "action_items": array of implied to-dos (empty if none)
- "dates_mentioned": array of dates YYYY-MM-DD (empty if none)
- "topics": array of 1-3 short topic tags (always at least one)
- "type": one of "observation", "task", "idea", "reference", "person_note"
Only extract what's explicitly there.
```
  Parse failure fallback: `{ topics: ["uncategorized"], type: "observation" }`.

**MCP server identity:** name `open-brain`, version `1.0.0`. A NEW `McpServer` + `StreamableHTTPTransport` is built per HTTP request (stateless); the `mcp-session-id` response header is deleted.

**Registered tools (6 total):**
1. `search` — ChatGPT compatibility (restricted connector surfaces, company knowledge, deep research require exact read-only `search`/`fetch` shapes). Input `{query: string}`. Embeds query, runs the cosine SQL (threshold 0.5, limit 10 hardcoded), returns JSON `{results:[{id,title,url}]}`. Title = `<localeDateString> - <first 80 chars of content, whitespace-collapsed>`; url = `{CITATION_BASE_URL}/{id}`. `readOnlyHint: true`.
2. `fetch` — Input `{id: string}`. Returns JSON document `{id,title,text,url,metadata:{...metadata,created_at,updated_at}}`. `readOnlyHint: true`. (Note: selects `updated_at` — a column the init.sql schema does not create; see OPEN QUESTIONS.)
3. `search_thoughts` — Input `{query: string, limit?: number = 10, threshold?: number = 0.5}`. Inline SQL (not the `match_thoughts` function):
   `SELECT id, content, metadata, created_at, 1 - (embedding <=> $1::vector) AS similarity FROM thoughts WHERE 1 - (embedding <=> $1::vector) >= $2 ORDER BY embedding <=> $1::vector LIMIT $3` — embedding passed as string `[v1,v2,...]`. Output text format (parsed by SvelteKit dashboard, keep verbatim):
   ```
   Found N thought(s):

   --- Result 1 (87.3% match) ---
   Captured: <toLocaleDateString>
   Type: <metadata.type|unknown>
   Topics: a, b            (if present)
   People: x, y            (if present)
   Actions: i1; i2         (if present)

   <content>
   ```
   No results: `No thoughts found matching "<query>".`
4. `list_thoughts` — Input `{limit?=10, type?, topic?, person?, days?}`. Filters: `metadata->>'type' = $n`; `metadata->'topics' ? $n`; `metadata->'people' ? $n`; days: `created_at >= NOW() - INTERVAL '<days> days'` (interpolated, not parameterized — numeric via zod). Sorted `created_at DESC`. Output format:
   ```
   N recent thought(s):

   1. [<localeDate>] (<type|??> - topic1, topic2)
      <content>
   ```
   Empty: `No thoughts found.`
5. `thought_stats` — no input. Counts all rows, aggregates `metadata.type` / `metadata.topics[]` / `metadata.people[]` client-side, top 10 each. Output format:
   ```
   Total thoughts: N
   Date range: <oldest> -> <newest>

   Types:
     task: 12
   Top topics:            (if any)
     x: 3
   People mentioned:      (if any)
     y: 2
   ```
6. `capture_thought` — Input `{content: string}`. Runs `getEmbedding(content)` and `extractMetadata(content)` in `Promise.all`, then `INSERT INTO thoughts (content, embedding, metadata) VALUES ($1, $2::vector, $3::jsonb)` with metadata `{...extracted, source: "mcp"}`. Confirmation text: `Captured as <type> -- topic1, topic2 | People: ... | Actions: i1; i2`. Annotations: `readOnlyHint:false, openWorldHint:false, destructiveHint:false, idempotentHint:false`.

All tool errors return `{content:[{type:"text",text:"Error: <msg>"}], isError:true}`.

**HTTP layer (Hono):**
- CORS headers on everything: `Access-Control-Allow-Origin: *`; `Access-Control-Allow-Headers: authorization, content-type, x-brain-key, accept, mcp-session-id, mcp-protocol-version, last-event-id`; `Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE`. `OPTIONS *` → 200 "ok".
- **Auth on every request:** header `x-brain-key` **or** query param `?key=`; must equal `MCP_ACCESS_KEY`, else 401 `{"error":"Invalid or missing access key"}`.
- Claude Desktop quirk workaround: if request `Accept` lacks `text/event-stream`, the raw Request is rebuilt with `Accept: application/json, text/event-stream` (with `duplex: "half"` for Deno streaming bodies).

### 1.7 Verification & client config

```bash
kubectl get pods -n openbrain
kubectl exec -n openbrain openbrain-0 -c db -- psql -U postgres -d openbrain -c '\dt'
kubectl port-forward -n openbrain svc/openbrain 8000:8000 &
curl -X POST http://localhost:8000 -H "x-brain-key: YOUR_ACCESS_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```

MCP client config (Claude Desktop / any MCP client):
```json
{ "mcpServers": { "openbrain": {
    "url": "http://openbrain.openbrain.svc.cluster.local:8000",
    "transport": "http",
    "headers": { "x-brain-key": "YOUR_ACCESS_KEY" } } } }
```
(or `https://brain.yourdomain.com` via Ingress.)

README's "Expected Outcome" says `tools/list` returns **4 tools** (`search_thoughts`, `list_thoughts`, `thought_stats`, `capture_thought`) — the code actually registers 6 (adds `search`, `fetch`); README lags the code.

### 1.8 Local LLM instead of OpenRouter
Point both API bases at any OpenAI-compatible endpoint:
```yaml
EMBEDDING_API_BASE: "http://your-local-model:8080/v1"
EMBEDDING_API_KEY: "not-needed"
EMBEDDING_MODEL: "your-model-name"
CHAT_API_BASE / CHAT_API_KEY / CHAT_MODEL: same pattern
```
Adjust `vector(1536)` if the embedding dimension differs.

### 1.9 Troubleshooting knowledge (README)
- mcp-server CrashLoopBackOff → `kubectl logs -n openbrain openbrain-0 -c mcp-server`; usually invalid API key or unreachable embedding base URL.
- Missing tables → init SQL only runs on first startup; delete volume dir + pod to re-init.
- Vector dimension mismatch → fix `vector(1536)` in ConfigMap; drop/recreate table if DB exists.
- "Connection refused to database" → containers talk via 127.0.0.1 inside pod; check db container readiness/logs.

---

## 2. COMPLETE CONTAINER/SERVICE LIST FOR A SELF-HOSTED OPEN BRAIN

Minimum viable (what the k8s integration ships):
1. **`db`** — `ankane/pgvector:v0.5.1` (Postgres + pgvector). Persistent storage at `/var/lib/postgresql/data` (hostPath `/var/openbrain/db` in the manifest; use a PVC in real clusters). Initialized by `init.sql`.
2. **`mcp-server`** — `openbrain-mcp-server:latest`, built from `denoland/deno:2.3.3`. Port 8000. Serves MCP-over-HTTP with `x-brain-key`/`?key=` auth.

External/optional:
3. **Embedding + chat LLM** — external OpenRouter by default (`openai/text-embedding-3-small`, `openai/gpt-4o-mini`); for full self-hosting, one additional container running an OpenAI-compatible server (Ollama / llama.cpp / BitNet) reachable from the pod.
4. **Ingress controller** (Traefik/nginx) — only for external MCP access; TLS via secret.
5. **Dashboard (optional)** — one of:
   - **Next.js dashboard** (`open-brain-dashboard-next`): `next.config.ts` sets `output: "standalone"`, i.e. `next build` emits `.next/standalone/server.js` runnable in a bare `node:18+` container (no Dockerfile is shipped; standalone output is the Docker enabler). BUT it requires the **`open-brain-rest` REST gateway** (a Supabase Edge Function, in `integrations/open-brain-rest`, NOT among the digested sources) — for a Supabase-free deployment that REST layer must be reimplemented against Postgres.
   - **SvelteKit dashboard** (`open-brain-dashboard`): talks to the MCP endpoint directly (works against the self-hosted mcp-server unchanged), but its login uses **Supabase Auth** (email/password) — must be swapped for another auth for a Supabase-free stack. Ships with `@sveltejs/adapter-vercel` (`nodejs22.x`); switch to `adapter-node` to containerize.
6. **Capture bot(s) (optional)** — Slack/Discord capture are Supabase Edge Functions in the original design; self-hosted, each becomes a tiny HTTP webhook service (full logic in §5). This is the component to replace with **Nextcloud Talk** (see §5.4).

NOT included anywhere in these sources (needed for full dashboard functionality): `open-brain-rest` gateway, `agent-memory-api` function, `smart-ingest` function, `workflow-status` schema migration, `sensitivity-tiers` primitive (PR #110).

---

## 3. NEXT.JS DASHBOARD (`dashboards/open-brain-dashboard-next`)

By **@alanshurafa** (Alan Shurafa), v1.1.0, created 2026-03-23, updated 2026-03-31, "intermediate", 30 min. Package name `open-brain-dashboard`, version 0.1.0.

### 3.1 Tech stack & build
- **Next.js 16.2.4** (App Router), **React 19.2.4**, TypeScript 5, **Tailwind CSS 4** (dark theme), **iron-session ^8.0.4**, `@dnd-kit/core ^6.3.1` + `@dnd-kit/sortable ^10.0.0` + `@dnd-kit/utilities ^3.2.2`, `server-only`. Dev deps include `@opennextjs/cloudflare ^1.19.4` and `wrangler ^4.85.0`.
- Scripts: `dev` = `next dev`, `build` = `next build`, `start` = `next start`, `lint` = `eslint`.
- `next.config.ts`: `{ allowedDevOrigins: ["192.168.0.140"], output: "standalone" }` — **`output: "standalone"` is the Docker-build support**: after `next build`, run `node .next/standalone/server.js` in any Node 18+ container.
- Known build gotcha: SWC error if `node_modules` installed on a different platform — delete `node_modules` + `package-lock.json` and reinstall on target platform.

### 3.2 Deployment targets
- **Vercel**: `npx vercel --prod`; env vars `NEXT_PUBLIC_API_URL`, `SESSION_SECRET` in project settings. Free tier sufficient (server-side API calls only).
- **Cloudflare Workers** via `@opennextjs/cloudflare` (the older `@cloudflare/next-on-pages` caps at Next 15.5.x — cannot build this Next 16 app):
  ```bash
  npx opennextjs-cloudflare build     # NEXT_PUBLIC_API_URL read at BUILD time, baked into bundle
  npx opennextjs-cloudflare deploy
  wrangler secret put SESSION_SECRET --name ob-dashboard   # runtime secret
  ```
  `wrangler.jsonc`: name `ob-dashboard`, compatibility_date `2026-04-25`, compatibility_flags `["nodejs_compat","global_fetch_strictly_public"]`, main `.open-next/worker.js`, assets `.open-next/assets` binding `ASSETS`. `open-next.config.ts` = `defineCloudflareConfig({})`. Result URL: `https://ob-dashboard.<cf-subdomain>.workers.dev`.
  Rule: `NEXT_PUBLIC_API_URL` = build-time (rebuild to change); `SESSION_SECRET` = runtime (rotate with `wrangler secret put`).

### 3.3 Environment variables (.env.example, exact)
| var | required | notes |
|---|---|---|
| `NEXT_PUBLIC_API_URL` | yes | e.g. `https://YOUR-PROJECT-REF.supabase.co/functions/v1/open-brain-rest` (build-time) |
| `AGENT_MEMORY_API_URL` | no | else derived: replace trailing `/open-brain-rest` with `/agent-memory-api` in `NEXT_PUBLIC_API_URL` (also accepts `NEXT_PUBLIC_AGENT_MEMORY_API_URL`) |
| `AGENT_MEMORY_WORKSPACE_ID` | no | default `ob1-staging` |
| `AGENT_MEMORY_PROJECT_ID` | no | default `""` |
| `SESSION_SECRET` | yes | ≥32 chars, `openssl rand -hex 32`; module throws at load if missing/short: `SESSION_SECRET env var is required and must be at least 32 characters` |
| `AUTH_COOKIE_SECURE` | no | `true` forces secure cookies; otherwise secure if `NEXT_PUBLIC_APP_URL`/`APP_URL` starts `https://` or `VERCEL=1` |
| `OB1_DEMO_AUTH_BYPASS` | no | `true` = skip login entirely (local screenshot/walkthrough capture ONLY; never in shared/prod) |
| `OB1_DASHBOARD_DEMO_KEY` | no | demo api key, default `local-screenshot-key` |
| `RESTRICTED_PASSPHRASE_HASH` | no | SHA-256 hex of passphrase: `echo -n "passphrase" | shasum -a 256`; enables lock/unlock toggle for `sensitivity_tier`-restricted thoughts |

Demo walkthrough env combo: `OB1_DEMO_AUTH_BYPASS=true`, `OB1_DASHBOARD_DEMO_KEY=local-screenshot-key`, `NEXT_PUBLIC_API_URL=http://127.0.0.1:3024`, `AGENT_MEMORY_API_URL=http://127.0.0.1:3022` (local demo REST shim ports).

### 3.4 Auth model (lib/auth.ts, middleware.ts, login)
- iron-session cookie: name **`open_brain_session`**, ttl **24h**, httpOnly, sameSite `lax`, path `/`, secure per `shouldUseSecureCookie()`.
- Session data: `{ apiKey?, loggedIn?, restrictedUnlocked? }`.
- Login server action: validates entered key with `GET {NEXT_PUBLIC_API_URL}/health` + header `x-brain-key`; on OK stores key in session and redirects `/`. Errors: `Invalid API key or service unavailable` / `Could not reach API. Check your connection.`
- The key users log in with is the **`MCP_ACCESS_KEY`** from the OB1 backend secrets. No API key in env vars or browser storage; all API calls are server-side with the session key.
- `middleware.ts` redirects to `/login` when cookie missing; allowlist paths: `/login`, `/api*`, `/_next*`, `/brand*`, `/favicon*`; matcher `"/((?!_next/static|_next/image|favicon.ico).*)"`. `OB1_DEMO_AUTH_BYPASS=true` short-circuits everything.
- Helpers: `requireSession()` (API routes; throws `AuthError` → 401 before body parse), `requireSessionOrRedirect()` (server components → `/login`).
- Restricted content: `/api/restricted` — `POST {passphrase}` verifies SHA-256 against `RESTRICTED_PASSPHRASE_HASH` (401 `Incorrect passphrase`, 503 if unconfigured), sets `session.restrictedUnlocked=true`; `DELETE` re-locks; `GET` returns `{unlocked, configured}`. Locked (default) filters restricted thoughts from all views via `exclude_restricted` param.

### 3.5 Pages (10)
Dashboard (stats, recent, quick capture, workflow widget) · Workflow (kanban) · Browse/Thoughts (paginated table, filters type/source/importance) · Detail `/thoughts/[id]` (inline edit, delete, reflections, connections) · Search (semantic + full-text, match scores, pagination) · Add to Brain `/ingest` (auto-routing: short text <500 chars single paragraph → single capture; long/structured → extraction with dry-run preview) · Audit (low-quality-score review, bulk delete) · Duplicates (semantic similarity pairs, keep/delete/keep-both) · Agent Memory (+ `/agent-memory/[id]` inspector + `/agent-memory/traces` recall-trace debugger) · Login.

Sidebar core order: **Dashboard, Thoughts, Workflow, Agent Memory, Search, Audit, Duplicates**, then extensions, then trailing "Add".

### 3.6 REST API contract the dashboard requires (on `open-brain-rest`)
| endpoint | method | used by |
|---|---|---|
| `/health` | GET | login validation |
| `/thoughts` | GET | browse (params: `page`, `per_page`, `type`, `source_type`, `importance_min`, `quality_score_max`, `sort`, `order`, `exclude_restricted`, `status`) |
| `/thought/:id` | GET / PUT / DELETE | detail, inline edit `{content?,type?,importance?,status?}`, delete |
| `/search` | POST | body `{query, mode: "semantic"|"text", limit, page, exclude_restricted}` → `{results:[Thought & {similarity?, rank?}], count, total, page, per_page, total_pages, mode}` |
| `/stats` | GET | `?days=&exclude_restricted=` → `{total_thoughts, window_days, types{}, top_topics:[{topic,count}]}` |
| `/capture` | POST | `{content}` → `{thought_id, action, type, sensitivity_tier, content_fingerprint, message}` |
| `/thought/:id/reflection` | GET | `{reflections:[...]}` |
| `/ingest` | POST | `{text, dry_run?}` → `{job_id, status}` |
| `/ingestion-jobs` | GET | `{jobs:[...], count}` |
| `/duplicates` | GET | `?threshold=&limit=&offset=` → `{pairs:[{thought_id_a,thought_id_b,similarity,content_a,content_b,type_a,type_b,quality_a,quality_b,created_a,created_b}], threshold, limit, offset}` |

Auth on every call: header `x-brain-key: <apiKey>`. Kanban board fetches `type=task` and `type=idea` separately (API supports single type filter), per_page=100, sort importance desc, merges client-side.

Agent Memory API (`agent-memory-api`):
| endpoint | method |
|---|---|
| `/memories?workspace_id=...&project_id&review_status&lifecycle_status&runtime_name&memory_type&task_id_prefix&limit` | GET |
| `/memories/review?workspace_id&project_id` | GET |
| `/memories/:id` | GET |
| `/memories/:id/review` | PATCH body `{action, actor_label?, notes?, content?, summary?, visibility?, related_memory_id?}` |
| `/recall-traces/:request_id` | GET |

Review action vocabulary (exact): `confirm`, `edit`, `evidence_only`, `restrict_scope`, `mark_stale`, `merge`, `reject`, `dispute`, `supersede`.

### 3.7 Data vocabularies (lib/types.ts, exact)
- Thought fields: `id` (UUID **string** end-to-end — README IMPORTANT note), `uuid?`, `content`, `type`, `source_type`, `importance` (0–100), `quality_score`, `sensitivity_tier`, `metadata`, `created_at`, `updated_at`, `status|null`, `status_updated_at|null`.
- `THOUGHT_TYPES`: `task, idea, observation, reference, person_note, decision, lesson, meeting, journal` (superset of the MCP extraction's 5 types).
- `KANBAN_TYPES`: `task, idea` only.
- `KANBAN_STATUSES`: `new, planning, active, review, done` (labels New/Planning/Active/Review/Done; colors slate/violet/blue/amber/emerald). Flow: `New → Planning → Active → Review → Done → (Archived)`. Auto-archive from Done after **30 days**; archived hidden by default ("Show archived" toggle). Column collapse persisted in localStorage; @dnd-kit drag with 200ms touch hold.
- Priority mapping from importance: Critical ≥80 (set value 90, red), High ≥60 (70, orange), Medium ≥30 (50, yellow), Low ≥0 (20, slate).
- IngestionJob: `{id, source_label, status, extracted_count, added_count, skipped_count, appended_count, revised_count, created_at, completed_at}`; IngestionItem `action` vocabulary: `add, skip, create_revision, append_evidence`.
- Reflection: `{id, thought_id, trigger_context, options[], factors[{label,weight}], conclusion, confidence, reflection_type, metadata, created_at}`.
- AgentMemoryRecord (full column list): `id, thought_id, workspace_id, project_id, channel_kind, channel_id, channel_thread_id, visibility, memory_type, summary, content, lifecycle_status, provenance_status, confidence, created_by, runtime_name, runtime_version, provider, model, task_id, flow_id, can_use_as_instruction, can_use_as_evidence, requires_user_confirmation, review_status, last_confirmed_at, stale_after, idempotency_key, content_hash, metadata, created_at, updated_at` + child tables `agent_memory_source_refs` (`source_kind, uri, title, source_timestamp, metadata`) and `agent_memory_artifacts` (`artifact_kind, uri, description, metadata`). Recall traces: `agent_memory_traces` (`request_id, workspace_id, project_id, runtime_*, task_id, flow_id, channel_*, query, schema_version, request_payload, response_policy`) + items (`rank, similarity, ranking_score, returned, used, ignored_reason, use_policy_snapshot`).

### 3.8 Extensions system (EXTENSIONS.md)
Drop-in: a folder `app/<route>/page.tsx` + one entry in `extensions.config.ts`: `{ href: "/hello", label: "Hello", icon: "sparkles" }`. Extensions own their API helpers/types (not `lib/api.ts`, though `apiFetch` is exported for reuse). Auth via `requireSessionOrRedirect()`; `apiKey` is the OB1 access key → send as `x-brain-key`. Backend options: (a) sidecar Edge Function named e.g. `my-extension-api`, URL derived by string-replacing `open-brain-rest` in `NEXT_PUBLIC_API_URL` (pattern used by `agent-memory-api`); (b) add routes to `open-brain-rest` when data is tightly coupled. Icons: string keys in `ExtensionIcon` union, SVGs implemented in `components/Sidebar.tsx`, mapped in `EXTENSION_ICONS`. Contract intentionally unversioned. Extensions render between core nav and "Add".

### 3.9 MCP tie-in
The production MCP server has a `progress_task` tool ("Move the API redesign task to active", "Set priority on thought 42 to high"); new task/idea captures auto-assign `status: "new"`. (Not present in the K8s index.ts — see OPEN QUESTIONS.)

### 3.10 Troubleshooting (README)
"Could not reach API" → verify URL + `curl .../health -H "x-brain-key: KEY"`. SESSION_SECRET error → generate 32+ chars. Search empty → embeddings missing, run backfill. Ingest stuck "extracting" → `smart-ingest` Edge Function not deployed.

---

## 4. SVELTEKIT DASHBOARD (`dashboards/open-brain-dashboard`)

By **@headcrest** (Mads Bergdal), auth fixes by **@matthallett1**; v1.0.0, 2026-03-16, "intermediate", 1 hour.

### 4.1 Stack
Svelte **5** (runes enforced for non-node_modules files), SvelteKit ^2.50.2, Vite ^7.3.1, Tailwind 4 (`@tailwindcss/vite`), TypeScript. `@supabase/ssr ^0.6.1` + `@supabase/supabase-js ^2.56.0` for auth only. Adapter: `@sveltejs/adapter-vercel` with `runtime: 'nodejs22.x'` (adapter-auto also in devDeps). `.npmrc`: `engine-strict=true`. Scripts: `dev`=`vite dev` (port 5173), `build`=`vite build`, `preview`, `check`. Deploy: Vercel or Netlify with the same 4 env vars. **No Docker support shipped** (would need adapter-node swap).

### 4.2 Env (.env.local; SvelteKit needs restart after env edits; can symlink from repo root: `ln -s ../../.env.local dashboards/open-brain-dashboard/.env.local`)
- Preferred server-only: `MCP_URL` (e.g. `https://<ref>.supabase.co/functions/v1/open-brain-mcp`), `MCP_KEY` (the `MCP_ACCESS_KEY`; also visible in Claude Desktop connector URL after `?key=`).
- Backward-compatible but browser-exposed fallback: `PUBLIC_MCP_URL`, `PUBLIC_MCP_KEY`.
- `PUBLIC_SUPABASE_URL`, `PUBLIC_SUPABASE_ANON_KEY` (Supabase Dashboard → Settings → API).

### 4.3 Architecture
- **Auth**: Supabase email/password sign-in (`/signin`); `hooks.server.ts` creates `createServerClient` per request, syncs cookies, sets `locals.user` via `auth.getUser()`. OAuth-created users have no password → send recovery or create a second email/password user (Auto Confirm).
- **Data path**: browser → `POST /api/mcp` `{name, args}` → server proxy checks `locals.user` (401 otherwise) → forwards JSON-RPC 2.0 to `{MCP_URL}?key={MCP_KEY}` with `method:"tools/call"`, `params:{name, arguments}`, `Accept: application/json, text/event-stream`, `id: Date.now()`. Response parser handles both plain JSON and SSE (`data:` lines, last parseable wins, skips `[DONE]`). Errors: 502 `MCP upstream HTTP <status>` / MCP error message; 500 for missing env.
- **This dashboard consumes the MCP tools' human-readable TEXT output and regex-parses it back to structs** (fragile — depends on the exact formats in §1.6):
  - list: header regex `^\d+\.\s*\[([^\]]+)\]\s*\(([^)]+)\)$`, split on `\n\n(?=\d+\.\s*\[)`, strips `N recent thought(s):` prefix, bails on `No thoughts found.`
  - search: blocks matched by `--- Result \d+ ...` (also tolerates a trailing `Search strategy:` section — note: the production MCP server apparently appends this; the K8s one doesn't); parses `Captured:`, `Type:`, `Topics:`, `People:`, `Actions:` lines then content.
  - stats: parses `Total thoughts:`, `Types:`, `Top topics:`, `People mentioned:` sections with `key: number` lines.
  - capture: parses `Captured as (\w+)`.
  - Parsed thoughts get **client-side `crypto.randomUUID()`** ids (real ids are not in the text output).
- Tools used: `thought_stats`, `list_thoughts` (limit default 50, type/topic/person args), `search_thoughts` (query, limit 50), `capture_thought`.
- Features: total count header, search sorted by recency, filters by type (Observation/Task/Idea/Reference/Person Note), topic, people; full-text thought view; capture form.
- Troubleshooting notes: empty search but stats non-zero → (1) `OPENROUTER_API_KEY` missing in Supabase secrets (`supabase secrets list | grep OPENROUTER`), (2) `SELECT count(*) FROM thoughts WHERE embedding IS NULL`, (3) similarity below 0.5 threshold — try more specific phrase or lower `threshold`.
- **Self-hosting relevance**: point `MCP_URL` at the K8s mcp-server URL and `MCP_KEY` at `mcp-access-key` — the data path works unchanged; only Supabase Auth must be replaced.

---

## 5. CAPTURE BOTS (to be replaced by Nextcloud Talk)

### 5.1 Slack Capture (`integrations/slack-capture`) — by Nate B. Jones, v1.0.0, 2026-03-12, beginner, 15 min
Architecture: **Slack Events API webhook → Supabase Edge Function `ingest-thought` → direct DB insert into `thoughts`** (bypasses the MCP server entirely; uses service-role key).

Slack app setup (exact):
1. Private channel (e.g. `capture`); get Channel ID (starts `C`, from channel details).
2. Create app at api.slack.com/apps ("Open Brain"). Bot Token Scopes: `channels:history`, `groups:history`, `chat:write`. Install to workspace → Bot User OAuth Token (`xoxb-...`). `/invite @Open Brain` into the channel.
3. Deploy function first, then Event Subscriptions: Enable, Request URL = `https://<ref>.supabase.co/functions/v1/ingest-thought`, wait for Verified checkmark. Subscribe to bot events: **BOTH `message.channels` AND `message.groups`** (public channels fire the first, private the second; missing one = silent failure).

Function creation/deploy:
```bash
supabase functions new ingest-thought
supabase secrets set OPENROUTER_API_KEY=... SLACK_BOT_TOKEN=xoxb-... SLACK_CAPTURE_CHANNEL=C0...
supabase functions deploy ingest-thought --no-verify-jwt
```
`SUPABASE_URL` and `SUPABASE_SERVICE_ROLE_KEY` are auto-injected in Edge Functions. Rotating the OpenRouter key requires re-running `supabase secrets set` (function reads secrets at runtime).

Function logic (full source is inline in the README; behavioral spec):
1. Env: `SUPABASE_URL`, `SUPABASE_SERVICE_ROLE_KEY`, `OPENROUTER_API_KEY`, `SLACK_BOT_TOKEN`, `SLACK_CAPTURE_CHANNEL`. `OPENROUTER_BASE = https://openrouter.ai/api/v1`.
2. Slack `url_verification` handshake: echo `{challenge}` as JSON.
3. Filter: process only when `event.type === "message"` AND no `event.subtype` AND no `event.bot_id` AND `event.channel === SLACK_CAPTURE_CHANNEL` AND non-empty text. Everything else → 200 "ok".
4. **Deduplication** (Slack retries webhooks if no response within 3s): query `thoughts` where `metadata` contains `{ slack_ts: event.ts }`, limit 1; if found → 200 "ok".
5. `Promise.all`: embedding via OpenRouter `POST /embeddings` model `openai/text-embedding-3-small`; metadata via `POST /chat/completions` model `openai/gpt-4o-mini`, `response_format json_object`, the **same verbatim system prompt** as §1.6 (people / action_items / dates_mentioned / topics 1–3 / type ∈ observation, task, idea, reference, person_note), fallback `{topics:["uncategorized"],type:"observation"}`.
6. Insert: `supabase.from("thoughts").insert({ content, embedding, metadata: {...metadata, source: "slack", slack_ts: messageTs} })`. On insert error: threaded reply `Failed to capture: <msg>` + HTTP 500.
7. Threaded confirmation via `https://slack.com/api/chat.postMessage` (Bearer bot token, `thread_ts` = original message ts):
   ```
   Captured as *<type>* - topic1, topic2
   People: a, b                (if any)
   Action items: i1; i2        (if any)
   ```

Test phrase and expected reply (verbatim from README):
```
Sarah mentioned she's thinking about leaving her job to start a consulting business
→ Captured as person_note — career, consulting
  People: Sarah
  Action items: Check in with Sarah about consulting plans
```
Wait 5–10 s; verify a row in `thoughts` with content, embedding, metadata.

Costs: embeddings ~$0.02/M tokens; extraction ~$0.15/M input tokens; ~$0.10–0.30/month at 20 thoughts/day.

Troubleshooting vocabulary: "Request URL not verified" → redeploy; messages not triggering → both events subscribed + bot invited + channel ID matches; duplicates → dedupe on `slack_ts`; nothing in DB → check Edge Function logs, OpenRouter key/credits (`supabase secrets list`); no reply → bot token or `chat:write` scope missing (reinstall app after scope changes); metadata off → normal, metadata is a convenience layer, the embedding is the primary retrieval mechanism.

### 5.2 Discord Capture (`integrations/discord-capture`) — OB1 Team, v1.0.0, 2026-03-11, intermediate, 30 min
**Stub only — README steps are literally `<!-- TODO: Fill in step-by-step instructions -->`; no code ships in this folder.** Intended design (from README):
- Discord bot monitors designated channels; Edge Function `discord-capture` (`supabase functions deploy discord-capture`); requires **Message Content Intent** (Developer Portal → Bot → Privileged Gateway Intents).
- Config: bot token, channel IDs to monitor, Supabase keys, OpenRouter key.
- Stored metadata (exact): `source: "discord"`, `server` (server name), `channel` (channel name), `author` (Discord username), `timestamp` (original message timestamp).
- `UPDATE_ON_EDIT=true` env → message edits update the existing thought instead of creating a new one (default: new thought per edit).
- Logs: `supabase functions logs discord-capture`; most common failure = missing/incorrect `SUPABASE_SERVICE_ROLE_KEY`.

### 5.3 The generic capture-bot contract (what any replacement must implement)
1. Receive message events from the chat platform (webhook or gateway), restricted to one designated capture channel/room.
2. Ignore: bot/own messages, message subtypes/edits (unless UPDATE_ON_EDIT), empty text, other channels.
3. Deduplicate on a platform message id/timestamp stored inside `metadata` (Slack uses `slack_ts`); required because platforms retry webhooks (Slack: >3 s timeout).
4. In parallel: 1536-dim embedding (`text-embedding-3-small`-compatible) + LLM metadata extraction with the verbatim prompt of §1.6.
5. INSERT into `thoughts (content, embedding, metadata)` with `metadata = {…extracted, source: "<platform>", <platform>_ts/id: …}`.
6. Reply in-thread with the `Captured as <type> — topics… / People:… / Action items:…` confirmation.

### 5.4 Nextcloud Talk replacement notes (mapping)
- Event source: Nextcloud Talk webhook bot (Talk bot API: bots receive room messages via signed webhooks, `OCS`/bot secret HMAC) scoped to one capture room ↔ Slack channel filter.
- Dedupe key: Talk message id → `metadata.talk_id`.
- Confirmation: Talk bot reply-to-message ↔ Slack `thread_ts` reply.
- Insert path in a Supabase-free stack: either direct Postgres INSERT (like the Slack function does via service role) or call the self-hosted MCP server's `capture_thought` (loses custom `source`/dedupe metadata unless the tool is extended — the K8s server hardcodes `source: "mcp"`).

---

## 6. REBUILD RECIPE (condensed, Supabase-free)

1. Postgres+pgvector container (`ankane/pgvector:v0.5.1` or newer pgvector image), run §1.5 init.sql (adjust `vector(N)` to your embedder).
2. Build + run the Deno MCP server (Dockerfile §1.3, index.ts behavior §1.6) with env of §1.6; expose 8000 behind TLS ingress; clients authenticate with `x-brain-key` header or `?key=`.
3. Optional local LLM container exposing OpenAI-compatible `/v1/embeddings` + `/v1/chat/completions`; point `EMBEDDING_API_BASE`/`CHAT_API_BASE` at it.
4. Dashboard: SvelteKit one works against MCP directly (swap Supabase Auth); Next.js one needs an `open-brain-rest`-compatible REST layer (contract fully specified in §3.6–3.7) implemented against Postgres. Both containerizable (Next: `output:"standalone"`; Svelte: switch to adapter-node).
5. Capture bot: implement §5.3 contract as a small HTTP service (Nextcloud Talk bot), writing directly to Postgres.
6. Verify: `tools/list` curl (§1.7), `capture_thought` → row appears, `search_thoughts` returns `--- Result 1 (xx.x% match) ---` blocks.

---

## OPEN QUESTIONS

1. **`open-brain-rest` gateway not in sources.** The Next.js dashboard depends entirely on `integrations/open-brain-rest` (Supabase Edge Function) whose implementation was not among the digested directories. Its request/response contract is reconstructed in §3.6/§3.7 from the dashboard client code, but server-side behaviors (fingerprinting, sensitivity-tier assignment on `/capture`, semantic vs full-text search implementation, duplicates detection SQL) are unknown. Same for `agent-memory-api` and `smart-ingest`.
2. **Schema drift between the K8s minimal schema and production OB1.** K8s `thoughts` has `id BIGSERIAL` + type/topics/people inside `metadata`; production Supabase (per the Next dashboard) has UUID string ids plus first-class columns `type, source_type, importance, quality_score, sensitivity_tier, status, status_updated_at, updated_at` (workflow-status migration, sensitivity-tiers primitive PR #110). A fully self-hosted stack that also runs the Next dashboard must merge both schemas; the merged migration isn't in these sources.
3. **`fetch` tool selects `updated_at`** from `thoughts`, but init.sql never creates that column — the K8s `fetch` tool as shipped would error on a fresh install (untested edge? or upstream schema had the column). Verify before relying on `fetch`.
4. **README/code drift**: K8s README says `tools/list` returns 4 tools; code registers 6 (`search`, `fetch` added for ChatGPT compatibility).
5. **`progress_task` MCP tool** (used by the Workflow board's conversational updates) exists in the production Supabase MCP server but NOT in the K8s `index.ts`. A self-hosted parity build would need to port it (and status auto-assignment `status:"new"` on task/idea capture).
6. **`list_thoughts` `days` interpolation**: `INTERVAL '<days> days'` is string-interpolated into SQL (zod types it as number, limiting injection risk, but non-integer input handling is unverified).
7. **Search output "Search strategy:" section**: the SvelteKit parser tolerates a trailing `Search strategy:` block that the production MCP server apparently emits; the K8s server does not. Harmless, but indicates the two servers' text outputs are not byte-identical.
8. **Discord capture is a stub** — no shipped code; only the intended metadata shape and env vars are documented.
9. **hostPath volume** (`/var/openbrain/db`) pins the DB to one node; for multi-node clusters a PVC/StorageClass substitution is needed (not provided).
10. **No Dockerfile ships for either dashboard** — Next.js has `output: "standalone"` (Docker-ready build output) and the README documents Vercel/Cloudflare-Workers paths only; the SvelteKit dashboard ships the Vercel adapter (nodejs22.x). Containerfiles must be authored by the rebuilder.
