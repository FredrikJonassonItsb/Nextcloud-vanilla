/**
 * HTTP layer (Hono):
 *
 *   GET  /healthz  — no auth; live DB check (SELECT 1) + pending backlog.
 *   POST /ingest   — Bearer BRAIN_KEY; {content, source, author?, metadata?};
 *                    write firewall => 422 with Swedish reason.
 *   ALL  /mcp      — Bearer BRAIN_KEY; streamable-HTTP MCP endpoint,
 *                    stateless per-request McpServer (OB1 pattern).
 *
 * Auth: `Authorization: Bearer <BRAIN_KEY>` per CONTRACTS §5. For
 * compatibility with stock OB1 clients we also accept the `x-brain-key`
 * header and the `?key=` query parameter (strict superset of the contract).
 */

import { Hono } from "hono";
import { StreamableHTTPTransport } from "@hono/mcp";
import { createHash, timingSafeEqual } from "node:crypto";
import { buildMcpServer } from "./mcp.js";
import { FirewallError } from "./firewall.js";

const CORS_HEADERS = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Headers":
    "authorization, content-type, x-brain-key, accept, mcp-session-id, mcp-protocol-version, last-event-id",
  "Access-Control-Allow-Methods": "GET, POST, OPTIONS, DELETE",
};

function safeEqual(a, b) {
  // Hash both sides: constant-time compare without leaking length.
  const ha = createHash("sha256").update(String(a)).digest();
  const hb = createHash("sha256").update(String(b)).digest();
  return timingSafeEqual(ha, hb);
}

export function createApp({ config, store, pool, log = console }) {
  const app = new Hono();

  const authorized = (c) => {
    const authHeader = c.req.header("authorization") || "";
    const bearer = authHeader.startsWith("Bearer ") ? authHeader.slice(7).trim() : "";
    const provided =
      bearer || c.req.header("x-brain-key") || new URL(c.req.url).searchParams.get("key") || "";
    return provided.length > 0 && safeEqual(provided, config.brainKey);
  };

  app.options("*", (c) => c.text("ok", 200, CORS_HEADERS));

  app.get("/healthz", async (c) => {
    try {
      await pool.query("SELECT 1");
      let pending = null;
      try {
        pending = await store.pendingCount();
      } catch {
        // thoughts table may not exist yet during first boot — DB itself is up.
      }
      return c.json({ ok: true, db: "ok", embed_pending: pending }, 200, CORS_HEADERS);
    } catch (err) {
      log.error(`[healthz] db check failed: ${err.message}`);
      return c.json({ ok: false, db: "unreachable" }, 503, CORS_HEADERS);
    }
  });

  app.post("/ingest", async (c) => {
    if (!authorized(c)) {
      return c.json({ error: "unauthorized" }, 401, CORS_HEADERS);
    }
    let body;
    try {
      body = await c.req.json();
    } catch {
      return c.json({ error: "invalid_json", reason: "Kroppen måste vara giltig JSON." }, 400, CORS_HEADERS);
    }
    const { content, source, author, metadata } = body || {};
    if (typeof content !== "string" || !content.trim()) {
      return c.json(
        { error: "content_required", reason: "Fältet 'content' (icke-tom sträng) krävs." },
        400,
        CORS_HEADERS
      );
    }
    if (typeof source !== "string" || !source.trim()) {
      return c.json(
        { error: "source_required", reason: "Fältet 'source' (icke-tom sträng) krävs." },
        400,
        CORS_HEADERS
      );
    }
    if (author != null && typeof author !== "string") {
      return c.json(
        { error: "invalid_author", reason: "Fältet 'author' måste vara en sträng." },
        400,
        CORS_HEADERS
      );
    }
    if (metadata != null && (typeof metadata !== "object" || Array.isArray(metadata))) {
      return c.json(
        { error: "invalid_metadata", reason: "Fältet 'metadata' måste vara ett objekt." },
        400,
        CORS_HEADERS
      );
    }

    try {
      const r = await store.captureThought({
        content,
        source: source.trim(),
        author: author ? author.trim() : undefined,
        extraMetadata: metadata || {},
      });
      return c.json(
        {
          id: r.id,
          action: r.inserted ? "created" : "merged",
          embed_pending: Boolean(r.embedPending),
        },
        201,
        CORS_HEADERS
      );
    } catch (err) {
      if (err instanceof FirewallError) {
        // Audit line without content (never log the blocked payload).
        log.warn(`[firewall] blocked /ingest write (pattern=${err.patternId}, source=${source})`);
        return c.json(
          { error: "blocked_by_firewall", pattern: err.patternId, reason: err.message },
          422,
          CORS_HEADERS
        );
      }
      log.error(`[ingest] failed: ${err.message}`);
      return c.json({ error: "internal_error" }, 500, CORS_HEADERS);
    }
  });

  app.all("/mcp", async (c) => {
    if (!authorized(c)) {
      // JSON-RPC error body on a 401 so both curl-style smoke tests (status
      // code) and MCP clients (envelope) get a usable signal.
      return c.json(
        {
          jsonrpc: "2.0",
          error: { code: -32001, message: "Unauthorized: missing or invalid Bearer BRAIN_KEY." },
          id: null,
        },
        401,
        CORS_HEADERS
      );
    }

    // Claude Desktop connectors don't send Accept: text/event-stream — patch it in
    // (vendored OB1 workaround, see OB1 issue #33).
    if (!c.req.header("accept")?.includes("text/event-stream")) {
      const headers = new Headers(c.req.raw.headers);
      headers.set("Accept", "application/json, text/event-stream");
      const patched = new Request(c.req.raw.url, {
        method: c.req.raw.method,
        headers,
        body: c.req.raw.body,
        duplex: "half",
      });
      Object.defineProperty(c.req, "raw", { value: patched, writable: true });
    }

    const server = buildMcpServer({ store, citationBaseUrl: config.citationBaseUrl });
    const transport = new StreamableHTTPTransport();
    await server.connect(transport);
    const response = await transport.handleRequest(c);
    if (!response) {
      return c.json({ error: "No response from MCP transport" }, 500, CORS_HEADERS);
    }
    response.headers.delete("mcp-session-id"); // stateless: strip any session hint
    for (const [k, v] of Object.entries(CORS_HEADERS)) response.headers.set(k, v);
    return response;
  });

  app.notFound((c) => c.json({ error: "not_found" }, 404, CORS_HEADERS));

  return app;
}
