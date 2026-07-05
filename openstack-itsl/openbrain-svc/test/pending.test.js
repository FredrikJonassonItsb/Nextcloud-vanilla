/**
 * Pending-mode unit tests (CONTRACTS §5):
 *  - no OPENROUTER_API_KEY  => embedding=NULL + metadata.embed_pending=true
 *  - embedding call failure => same pending behavior
 *  - firewall runs BEFORE any embedding call
 *  - search degrades to ILIKE with warning flag
 *  - backfillOnce embeds pending rows and strips the flags
 */

import { test } from "node:test";
import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import { createStore, FALLBACK_WARNING } from "../src/store.js";
import { loadFirewall, FirewallError } from "../src/firewall.js";

const firewall = loadFirewall(
  fileURLToPath(new URL("../../stack/shared/pii-patterns.json", import.meta.url))
);

/** Fake pg pool: routes queries on SQL substring, records every call. */
function fakePool(routes = []) {
  const calls = [];
  return {
    calls,
    async query(text, params) {
      calls.push({ text, params });
      for (const r of routes) {
        if (text.includes(r.match)) return r.reply(text, params);
      }
      return { rows: [], rowCount: 0 };
    },
    findCall(match) {
      return calls.find((c) => c.text.includes(match));
    },
  };
}

function fakeEmbedder({ hasKey = true, embedImpl, extractImpl } = {}) {
  const state = { embedCalls: 0, extractCalls: 0 };
  return {
    state,
    hasKey: () => hasKey,
    async embed(text) {
      state.embedCalls++;
      if (embedImpl) return embedImpl(text);
      return [0.1, 0.2, 0.3];
    },
    async extractMetadata(text) {
      state.extractCalls++;
      if (extractImpl) return extractImpl(text);
      return { topics: ["test"], type: "observation", people: [] };
    },
  };
}

const quietLog = { info() {}, warn() {}, error() {} };

test("capture without API key stores NULL embedding + embed_pending flag", async () => {
  const pool = fakePool([
    {
      match: "INSERT INTO thoughts",
      reply: () => ({ rows: [{ id: "11111111-1111-4111-8111-111111111111", inserted: true }] }),
    },
  ]);
  const embedder = fakeEmbedder({ hasKey: false });
  const store = createStore({ pool, embedder, firewall, log: quietLog });

  const r = await store.captureThought({ content: "En ren arbetsanteckning.", source: "talk" });

  assert.equal(r.embedPending, true);
  const insert = pool.findCall("INSERT INTO thoughts");
  assert.ok(insert, "INSERT executed");
  assert.equal(insert.params[2], null, "embedding param is NULL");
  const meta = JSON.parse(insert.params[3]);
  assert.equal(meta.embed_pending, true);
  assert.equal(meta.meta_pending, true);
  assert.deepEqual(meta.topics, ["uncategorized"], "OB1 fallback metadata");
  assert.equal(meta.source, "talk");
  assert.equal(embedder.state.embedCalls, 0, "no embedding call attempted without key");
});

test("capture with key but failing embed API degrades to pending", async () => {
  const pool = fakePool([
    {
      match: "INSERT INTO thoughts",
      reply: () => ({ rows: [{ id: "22222222-2222-4222-8222-222222222222", inserted: true }] }),
    },
  ]);
  const embedder = fakeEmbedder({
    hasKey: true,
    embedImpl: () => {
      throw new Error("OpenRouter /embeddings failed: 500");
    },
    extractImpl: () => ({ topics: ["drift"], type: "idea" }),
  });
  const store = createStore({ pool, embedder, firewall, log: quietLog });

  const r = await store.captureThought({ content: "Idé om caddy-routing.", source: "mcp" });

  assert.equal(r.embedPending, true);
  assert.equal(r.metaPending, false, "metadata extraction succeeded");
  const insert = pool.findCall("INSERT INTO thoughts");
  assert.equal(insert.params[2], null);
  const meta = JSON.parse(insert.params[3]);
  assert.equal(meta.embed_pending, true);
  assert.equal(meta.meta_pending, undefined);
  assert.deepEqual(meta.topics, ["drift"]);
});

test("firewall blocks BEFORE any embedding call and nothing is stored", async () => {
  const pool = fakePool();
  const embedder = fakeEmbedder({ hasKey: true });
  const store = createStore({ pool, embedder, firewall, log: quietLog });

  await assert.rejects(
    () => store.captureThought({ content: "pnr 850712-1234", source: "talk" }),
    (err) => err instanceof FirewallError && err.httpStatus === 422
  );
  assert.equal(embedder.state.embedCalls, 0, "embedding API never called");
  assert.equal(embedder.state.extractCalls, 0, "extraction API never called");
  assert.equal(pool.calls.length, 0, "no SQL executed");
});

test("firewall also checks caller-provided metadata", async () => {
  const pool = fakePool();
  const embedder = fakeEmbedder({ hasKey: true });
  const store = createStore({ pool, embedder, firewall, log: quietLog });

  await assert.rejects(
    () =>
      store.captureThought({
        content: "helt ren text",
        source: "talk",
        extraMetadata: { note: "sk-or-v1-deadbeef" },
      }),
    FirewallError
  );
  assert.equal(pool.calls.length, 0);
});

test("search without key uses ILIKE fallback with warning flag", async () => {
  const pool = fakePool([
    {
      match: "ILIKE",
      reply: () => ({
        rows: [
          {
            id: "33333333-3333-4333-8333-333333333333",
            content: "caddy routing klar",
            metadata: { type: "observation" },
            created_at: new Date().toISOString(),
            similarity: null,
          },
        ],
      }),
    },
  ]);
  const embedder = fakeEmbedder({ hasKey: false });
  const store = createStore({ pool, embedder, firewall, log: quietLog });

  const r = await store.searchThoughts({ query: "caddy routing", limit: 5 });

  assert.equal(r.fallback, true);
  assert.equal(r.warning, FALLBACK_WARNING);
  assert.equal(r.rows.length, 1);
  const call = pool.findCall("ILIKE");
  assert.ok(call, "ILIKE query used");
  assert.ok(call.params[0].includes("%caddy%"), "term patterns built");
});

test("search with failing embed API also falls back to ILIKE", async () => {
  const pool = fakePool([{ match: "ILIKE", reply: () => ({ rows: [] }) }]);
  const embedder = fakeEmbedder({
    hasKey: true,
    embedImpl: () => {
      throw new Error("boom");
    },
  });
  const store = createStore({ pool, embedder, firewall, log: quietLog });

  const r = await store.searchThoughts({ query: "något", limit: 5 });
  assert.equal(r.fallback, true);
  assert.ok(pool.findCall("ILIKE"));
});

test("search with working key uses vector SQL (no fallback)", async () => {
  const pool = fakePool([
    { match: "<=>", reply: () => ({ rows: [] }) },
  ]);
  const embedder = fakeEmbedder({ hasKey: true });
  const store = createStore({ pool, embedder, firewall, log: quietLog });

  const r = await store.searchThoughts({ query: "x y", limit: 5, threshold: 0.5 });
  assert.equal(r.fallback, false);
  const call = pool.findCall("<=>");
  assert.ok(call, "vector query used");
  assert.match(call.params[0], /^\[[-\d.,eE]+\]$/, "vector literal param");
});

test("backfillOnce embeds pending rows and strips flags", async () => {
  const pending = {
    id: "44444444-4444-4444-8444-444444444444",
    content: "pending tanke",
    metadata: { embed_pending: true, meta_pending: true, topics: ["uncategorized"] },
    needs_embedding: true,
  };
  const pool = fakePool([
    { match: "SELECT id, content, metadata", reply: () => ({ rows: [pending] }) },
    { match: "UPDATE thoughts", reply: () => ({ rows: [], rowCount: 1 }) },
  ]);
  const embedder = fakeEmbedder({
    hasKey: true,
    extractImpl: () => ({ topics: ["drift"], type: "task" }),
  });
  const store = createStore({ pool, embedder, firewall, log: quietLog });

  const r = await store.backfillOnce(10);

  assert.equal(r.processed, 1);
  assert.equal(r.failed, 0);
  const update = pool.findCall("UPDATE thoughts");
  assert.ok(update, "UPDATE executed");
  assert.equal(update.params[0], pending.id);
  assert.match(update.params[1], /^\[/, "embedding vector literal set");
  const patch = JSON.parse(update.params[2]);
  assert.deepEqual(patch.topics, ["drift"], "metadata re-extracted");
  assert.ok(update.text.includes("- 'embed_pending'"), "embed_pending stripped");
  assert.ok(update.text.includes("- 'meta_pending'"), "meta_pending stripped");
});

test("backfillOnce is a no-op without API key", async () => {
  const pool = fakePool();
  const embedder = fakeEmbedder({ hasKey: false });
  const store = createStore({ pool, embedder, firewall, log: quietLog });

  const r = await store.backfillOnce(10);
  assert.deepEqual(r, { processed: 0, failed: 0, skipped: true });
  assert.equal(pool.calls.length, 0);
});

test("author column path writes author param (team brain)", async () => {
  const pool = fakePool([
    {
      match: "INSERT INTO thoughts",
      reply: () => ({ rows: [{ id: "55555555-5555-4555-8555-555555555555", inserted: true }] }),
    },
  ]);
  const embedder = fakeEmbedder({ hasKey: false });
  const store = createStore({
    pool,
    embedder,
    firewall,
    log: quietLog,
    authorColumn: true,
    defaultAuthor: "atlas-claude",
  });

  await store.captureThought({ content: "team-anteckning utan pii", source: "mcp" });
  const insert = pool.findCall("INSERT INTO thoughts");
  assert.ok(insert.text.includes(", author"), "author column included");
  assert.equal(insert.params[4], "atlas-claude");
  const meta = JSON.parse(insert.params[3]);
  assert.equal(meta.author, "atlas-claude", "author mirrored into metadata");
});
