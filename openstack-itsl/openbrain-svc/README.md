# openbrain-svc — per-brain Open Brain MCP service

Per-brain memory service for the ITSL Open Stack (CONTRACTS.md §5). One
instance per brain (`brain-reb` … `brain-team`), each with its own
`DATABASE_URL` and `BRAIN_KEY`.

Vendored from **OB1** `integrations/kubernetes-deployment/index.ts` (the
self-hosted Postgres variant of Nate B. Jones' Open Brain MCP server), ported
from Deno to **Node 22 + plain JS (ESM)** and extended per CONTRACTS.

## Why Node instead of Deno

CONTRACTS/task mandate Node 22 unless Deno is clearly cheaper. It is not: the
OB1 repo itself validates the exact server pattern on Node
(`server/test-stateless.mjs` runs `@hono/mcp` + `@modelcontextprotocol/sdk`
under `@hono/node-server`), so the port is mechanical — swap deno-postgres for
`pg`, `Deno.env` for `process.env`, `Deno.serve` for `@hono/node-server`. No
build step (plain ESM), smaller image, same toolchain as capture-bot/runner.

## Endpoints

| Endpoint | Auth | Semantics |
|---|---|---|
| `ALL /mcp` | Bearer `BRAIN_KEY` | Streamable-HTTP MCP, stateless per-request server (OB1 pattern, `mcp-session-id` stripped) |
| `POST /ingest` | Bearer `BRAIN_KEY` | `{content, source, author?, metadata?}` → firewall → dedupe-upsert → `201 {id, action: created\|merged, embed_pending}` |
| `GET /healthz` | none | Live `SELECT 1` + pending-backlog count; `503` when DB unreachable |

Auth: `Authorization: Bearer <BRAIN_KEY>` (CONTRACTS §5). For stock-OB1
client compatibility the `x-brain-key` header and `?key=` query parameter are
also accepted (strict superset). Invalid key ⇒ HTTP 401 (JSON-RPC `-32001`
envelope on `/mcp`).

## MCP tools (OB1-verbatim names, schemas, output formats)

`search`, `fetch` (ChatGPT read-only compatibility pair), `search_thoughts`,
`list_thoughts`, `thought_stats`, `capture_thought` — exactly what OB1 ships,
so Nate-compatible clients (Claude Desktop connectors, ChatGPT connectors,
the SvelteKit dashboard's text parsers) work unchanged. ITSL deltas are
additive only:

- `capture_thought` writes through the fingerprint-dedupe upsert path
  (BYGGPLAN §2.2) and appends `| Duplicate: metadata merged` /
  `| Embedding: pending (backfill queued)` suffixes when applicable.
- Search results carry a `warning` (JSON) / `Warning:` line (text) when the
  ILIKE fallback was used.

## Write firewall (CONTRACTS §3)

`../stack/shared/pii-patterns.json` (same file in the image at
`/srv/stack/shared/`) is loaded at boot — the service refuses to start
without it. Every write (`capture_thought` + `/ingest`, content AND
caller-provided metadata) is checked **before any OpenRouter call**. A hit ⇒
HTTP 422 `{error: "blocked_by_firewall", pattern, reason}` with the Swedish
refusal (`Blockerat: …`); nothing stored, nothing sent to any external API,
audit log line without the content.

## Pending mode (CONTRACTS §5)

No/failing `OPENROUTER_API_KEY` ⇒ thought saved with `embedding = NULL` and
`metadata.embed_pending = true` (plus `meta_pending` when LLM metadata
extraction was skipped/failed — retried by the same worker). The backfill
worker runs every 5 min (`BACKFILL_INTERVAL_MS`) + once ~15 s after boot,
embeds/extracts pending rows and strips the flags. Search degrades to ILIKE
term matching with an explicit warning flag until embeddings exist.

## Schema

Idempotent bootstrap on every boot (satisfies deploy.sh's migrations step):
OB1 **production** schema verbatim (UUID ids, `updated_at` + trigger,
`content_fingerprint` + partial unique index, `match_thoughts()`,
`upsert_thought()`, hnsw/gin/btree indexes) — coexists with brain-db init SQL
(`IF NOT EXISTS` / `CREATE OR REPLACE`). The `author` column (brain_team) is
detected at boot; when present the write path fills it (request `author` >
`DEFAULT_AUTHOR` env > `"unknown"` + warning) and mirrors it into
`metadata.author`.

## Environment

| Var | Required | Default |
|---|---|---|
| `DATABASE_URL` | yes | — |
| `BRAIN_KEY` | yes | — |
| `OPENROUTER_API_KEY` | no (pending mode without it) | — |
| `EMBED_MODEL` | no | `openai/text-embedding-3-small` |
| `CHAT_MODEL` | no | `openai/gpt-4o-mini` |
| `OPENROUTER_BASE` | no | `https://openrouter.ai/api/v1` |
| `PORT` | no | `7100` (compose maps host 7101–7105) |
| `PII_PATTERNS_PATH` | no | `../stack/shared/pii-patterns.json` |
| `DEFAULT_AUTHOR` | no | — (agent code for MCP writes on brain_team) |
| `OPEN_BRAIN_CITATION_BASE_URL` | no | `https://openbrain.local/thoughts` |
| `BACKFILL_INTERVAL_MS` / `BACKFILL_BATCH_SIZE` | no | `300000` / `25` |

## Build / test

Build context is the **repo root** (the image needs `stack/shared/`):

```bash
docker build -f openbrain-svc/Dockerfile -t itsl/openbrain-svc .
docker build -f openbrain-svc/Dockerfile --target test .   # runs unit tests
# or locally / via mount:
docker run --rm -v "$PWD:/w" -w /w/openbrain-svc node:22-alpine sh -c "npm ci && npm test"
```

35 unit tests (node:test): firewall patterns against the real shared JSON,
pending mode (null embedding + flags, firewall-before-embedding ordering,
ILIKE fallback + warning, backfill flag-stripping, author column), HTTP layer
(Bearer auth 401, `/ingest` 201/400/422 with Swedish reason, `/healthz`
without auth, `/mcp` initialize handshake). Runtime image runs as non-root
`node`.
