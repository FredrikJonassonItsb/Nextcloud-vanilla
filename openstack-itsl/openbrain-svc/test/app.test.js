/**
 * HTTP-layer tests via Hono's app.request() (no sockets needed):
 * Bearer auth, /ingest validation + firewall 422 (Swedish reason),
 * /healthz without auth, /mcp initialize handshake.
 */

import { test } from "node:test";
import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import { createApp } from "../src/app.js";
import { createStore } from "../src/store.js";
import { loadFirewall } from "../src/firewall.js";

const firewall = loadFirewall(
  fileURLToPath(new URL("../../stack/shared/pii-patterns.json", import.meta.url))
);

const BRAIN_KEY = "test-brain-key-0123456789abcdef";

function buildTestApp({ dbOk = true } = {}) {
  const pool = {
    async query(text, params) {
      if (!dbOk) throw new Error("connection refused");
      if (text.includes("INSERT INTO thoughts")) {
        return { rows: [{ id: "66666666-6666-4666-8666-666666666666", inserted: true }] };
      }
      if (text.includes("COUNT(*)")) return { rows: [{ n: 0, count: 0 }] };
      return { rows: [] };
    },
  };
  const embedder = {
    hasKey: () => false,
    async embed() {
      throw new Error("no key");
    },
    async extractMetadata() {
      throw new Error("no key");
    },
  };
  const quietLog = { info() {}, warn() {}, error() {} };
  const store = createStore({ pool, embedder, firewall, log: quietLog });
  const config = {
    brainKey: BRAIN_KEY,
    citationBaseUrl: "https://openbrain.local/thoughts",
  };
  return createApp({ config, store, pool, log: quietLog });
}

test("GET /healthz requires no auth and reports db ok", async () => {
  const app = buildTestApp();
  const res = await app.request("/healthz");
  assert.equal(res.status, 200);
  const body = await res.json();
  assert.equal(body.ok, true);
  assert.equal(body.db, "ok");
});

test("GET /healthz returns 503 when the DB is down", async () => {
  const app = buildTestApp({ dbOk: false });
  const res = await app.request("/healthz");
  assert.equal(res.status, 503);
  const body = await res.json();
  assert.equal(body.ok, false);
});

test("POST /ingest without key -> 401", async () => {
  const app = buildTestApp();
  const res = await app.request("/ingest", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ content: "hej", source: "talk" }),
  });
  assert.equal(res.status, 401);
});

test("POST /ingest with wrong Bearer key -> 401", async () => {
  const app = buildTestApp();
  const res = await app.request("/ingest", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: "Bearer wrong-key",
    },
    body: JSON.stringify({ content: "hej", source: "talk" }),
  });
  assert.equal(res.status, 401);
});

test("POST /ingest clean content -> 201 with embed_pending flag", async () => {
  const app = buildTestApp();
  const res = await app.request("/ingest", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${BRAIN_KEY}`,
    },
    body: JSON.stringify({
      content: "Sarah funderar på konsultbolag — följ upp nästa vecka.",
      source: "talk",
      author: "rebecca",
      metadata: { talk_id: "msg-1" },
    }),
  });
  assert.equal(res.status, 201);
  const body = await res.json();
  assert.equal(body.action, "created");
  assert.equal(body.embed_pending, true, "no OpenRouter key in test => pending");
  assert.ok(body.id);
});

test("POST /ingest with personnummer -> 422 with Swedish reason", async () => {
  const app = buildTestApp();
  const res = await app.request("/ingest", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${BRAIN_KEY}`,
    },
    body: JSON.stringify({ content: "klient pnr 850712-1234", source: "talk" }),
  });
  assert.equal(res.status, 422);
  const body = await res.json();
  assert.equal(body.error, "blocked_by_firewall");
  assert.equal(body.pattern, "swedish_personnummer");
  assert.match(body.reason, /^Blockerat:/);
  assert.ok(!body.reason.includes("850712"), "blocked content never echoed");
});

test("POST /ingest missing source -> 400", async () => {
  const app = buildTestApp();
  const res = await app.request("/ingest", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${BRAIN_KEY}`,
    },
    body: JSON.stringify({ content: "hej" }),
  });
  assert.equal(res.status, 400);
  const body = await res.json();
  assert.equal(body.error, "source_required");
});

test("POST /mcp without key -> 401 with JSON-RPC error envelope", async () => {
  const app = buildTestApp();
  const res = await app.request("/mcp", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "tools/list", params: {} }),
  });
  assert.equal(res.status, 401);
  const body = await res.json();
  assert.equal(body.error.code, -32001);
});

test("POST /mcp initialize handshake works (x-brain-key compat header)", async () => {
  const app = buildTestApp();
  const res = await app.request("/mcp", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json, text/event-stream",
      "x-brain-key": BRAIN_KEY,
    },
    body: JSON.stringify({
      jsonrpc: "2.0",
      id: 1,
      method: "initialize",
      params: {
        protocolVersion: "2024-11-05",
        capabilities: {},
        clientInfo: { name: "test-client", version: "0.0.1" },
      },
    }),
  });
  assert.equal(res.status, 200);
  assert.ok(!res.headers.has("mcp-session-id"), "stateless: no session id header");
  const text = await res.text();
  let body;
  if (text.startsWith("{")) {
    body = JSON.parse(text);
  } else {
    const dataLine = text.split("\n").find((l) => l.startsWith("data: "));
    body = JSON.parse(dataLine.slice(6));
  }
  assert.ok(body.result?.protocolVersion, "initialize result returned");
  assert.equal(body.result.serverInfo.name, "open-brain", "OB1-compatible server identity");
});
