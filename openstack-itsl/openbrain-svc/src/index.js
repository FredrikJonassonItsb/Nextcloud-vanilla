/**
 * openbrain-svc entrypoint.
 *
 * Boot order:
 *   1. Load config (fail fast on missing DATABASE_URL / BRAIN_KEY).
 *   2. Load the shared write firewall (fail fast — never boot without it).
 *   3. Connect to Postgres, apply the idempotent schema (with retry while
 *      brain-db is still starting), detect the author column (brain_team).
 *   4. Serve /mcp, /ingest, /healthz.
 *   5. Start the 5-minute embed-backfill worker (CONTRACTS §5 pending mode).
 */

import { serve } from "@hono/node-server";
import { loadConfig } from "./config.js";
import { loadFirewall } from "./firewall.js";
import { createEmbedder } from "./openrouter.js";
import { createPool, ensureSchema, detectAuthorColumn } from "./db.js";
import { createStore } from "./store.js";
import { createApp } from "./app.js";

const log = console;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function ensureSchemaWithRetry(pool, { attempts = 60, delayMs = 5000 } = {}) {
  for (let i = 1; i <= attempts; i++) {
    try {
      await ensureSchema(pool, log);
      return;
    } catch (err) {
      log.warn(`[boot] schema not ready (attempt ${i}/${attempts}): ${err.message}`);
      if (i === attempts) throw err;
      await sleep(delayMs);
    }
  }
}

async function main() {
  const config = loadConfig();
  const firewall = loadFirewall(config.piiPatternsPath, { enabled: config.firewallEnabled });
  log.info(
    `[boot] firewall loaded from ${config.piiPatternsPath} (enabled=${config.firewallEnabled})`,
  );

  const pool = createPool(config.databaseUrl);
  await ensureSchemaWithRetry(pool);
  const authorColumn = await detectAuthorColumn(pool);
  log.info(`[boot] schema ready (author column: ${authorColumn ? "yes" : "no"})`);

  const embedder = createEmbedder({
    apiKey: config.openrouterApiKey,
    base: config.openrouterBase,
    embedModel: config.embedModel,
    chatModel: config.chatModel,
  });
  if (!embedder.hasKey()) {
    log.warn(
      "[boot] OPENROUTER_API_KEY not set — running in PENDING mode: " +
        "thoughts stored with embedding=NULL + metadata.embed_pending, search uses ILIKE fallback"
    );
  }

  const store = createStore({
    pool,
    embedder,
    firewall,
    log,
    authorColumn,
    defaultAuthor: config.defaultAuthor,
  });

  const app = createApp({ config, store, pool, log });
  const httpServer = serve({ fetch: app.fetch, port: config.port, hostname: "0.0.0.0" });
  log.info(
    `[boot] openbrain-svc listening on :${config.port} ` +
      `(mcp=/mcp ingest=/ingest healthz=/healthz, embed model=${config.embedModel})`
  );

  // Backfill worker: every 5 minutes (CONTRACTS §5) + an early pass at boot.
  const backfillTick = async () => {
    try {
      const r = await store.backfillOnce(config.backfillBatchSize);
      if (!r.skipped && (r.processed > 0 || r.failed > 0)) {
        log.info(`[backfill] processed=${r.processed} failed=${r.failed}`);
      }
    } catch (err) {
      log.error(`[backfill] tick failed: ${err.message}`);
    }
  };
  const firstRun = setTimeout(backfillTick, 15000);
  const interval = setInterval(backfillTick, config.backfillIntervalMs);

  const shutdown = async (signal) => {
    log.info(`[shutdown] received ${signal}`);
    clearTimeout(firstRun);
    clearInterval(interval);
    httpServer.close();
    try {
      await pool.end();
    } catch {
      // pool already closed
    }
    process.exit(0);
  };
  process.on("SIGTERM", () => shutdown("SIGTERM"));
  process.on("SIGINT", () => shutdown("SIGINT"));
}

main().catch((err) => {
  log.error(`[boot] fatal: ${err.message}`);
  process.exit(1);
});
