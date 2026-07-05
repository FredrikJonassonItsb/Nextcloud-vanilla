// capture-bot entrypoint — Node 22, single service, port 8790 (CONTRACTS §4/§6).
import http from 'node:http';
import pg from 'pg';
import { loadConfig, validateConfig } from './config.js';
import { createDedupe } from './dedupe.js';
import { loadPatterns, createFirewall } from './firewall.js';
import { createTalkClient, safeNotify } from './talk.js';
import { createBrainClient } from './brain.js';
import { createDeckClient } from './deck.js';
import { createEngineClient, formatLedger } from './engine.js';
import { createWebhookHandler } from './handler.js';

const log = {
  info: (o) => process.stdout.write(`${JSON.stringify({ level: 'info', time: new Date().toISOString(), ...o })}\n`),
  error: (o) => process.stderr.write(`${JSON.stringify({ level: 'error', time: new Date().toISOString(), ...o })}\n`),
};

const config = loadConfig();
validateConfig(config, log);

const pool = new pg.Pool({ connectionString: config.databaseUrl, max: 5 });
pool.on('error', (err) => log.error({ msg: 'pg pool error', err: String(err) }));
const dedupe = createDedupe((sql, params) => pool.query(sql, params));

let firewall;
try {
  firewall = createFirewall(loadPatterns(config.piiPatternsPath), { enabled: config.firewallEnabled });
  log.info({ msg: 'pii firewall loaded', path: config.piiPatternsPath, enabled: config.firewallEnabled });
} catch (err) {
  // Fail CLOSED: without patterns nothing may be captured.
  log.error({ msg: 'pii patterns failed to load — blocking all captures', err: String(err) });
  firewall = { check: () => ({ id: 'firewall-unavailable', message: 'brandväggen kunde inte laddas, capture pausad' }) };
}

const fetchFn = globalThis.fetch;
const talk = createTalkClient({ fetchFn, ncBase: config.ncBase, botSecrets: config.botSecrets, log });
const notify = safeNotify(talk, log);
const brains = createBrainClient({ fetchFn, brainUrls: config.brainUrls, brainKeys: config.brainKeys });
const deck = createDeckClient({
  fetchFn,
  ncBase: config.ncBase,
  botUser: config.botEngineUser,
  botPassword: config.botEnginePassword,
  bootstrapPath: config.bootstrapPath,
  log,
});
const engine = createEngineClient({
  fetchFn,
  ncBase: config.ncBase,
  botUser: config.botEngineUser,
  botPassword: config.botEnginePassword,
});

const handle = createWebhookHandler({ config, dedupe, firewall, brains, notify, deck, engine, formatLedger, log });

// Schema init with retry — brain-db may start after us; never crash on it.
let dbReady = false;
(async () => {
  for (let attempt = 1; ; attempt += 1) {
    try {
      await dedupe.ensureSchema();
      dbReady = true;
      log.info({ msg: 'engine_meta.capture_seen ready' });
      return;
    } catch (err) {
      if (attempt === 1 || attempt % 10 === 0) {
        log.error({ msg: 'capture_seen schema init failed, retrying', attempt, err: String(err) });
      }
      await new Promise((r) => setTimeout(r, 3000));
    }
  }
})();

const MAX_BODY = 1024 * 1024; // 1 MiB — Talk messages cap at 32k chars.

function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY) {
        reject(Object.assign(new Error('payload too large'), { statusCode: 413 }));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

function send(res, status, body) {
  const data = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) });
  res.end(data);
}

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && req.url === '/healthz') {
      send(res, 200, { ok: true, dbReady });
      return;
    }
    // /bot (legacy) or /bot/<slug> — Talk requires a UNIQUE url per installed
    // bot, so each agent bot gets its own path; the slug selects the secret.
    const botMatch = req.url?.match(/^\/bot(?:\/([a-z]+))?(?:\?.*)?$/);
    if (botMatch) {
      if (req.method !== 'POST') {
        send(res, 405, { error: 'method not allowed' });
        return;
      }
      const rawBody = await readBody(req);
      const headers = {};
      for (const [k, v] of Object.entries(req.headers)) {
        headers[k.toLowerCase()] = Array.isArray(v) ? v[0] : v;
      }
      const result = await handle(rawBody, headers, botMatch[1]);
      send(res, result.status, result.body);
      return;
    }
    send(res, 404, { error: 'not found' });
  } catch (err) {
    log.error({ msg: 'request failed', url: req.url, err: String(err) });
    send(res, err.statusCode || 500, { error: 'internal error' });
  }
});

server.listen(config.port, () => {
  log.info({ msg: 'capture-bot listening', port: config.port, rooms: Object.keys(config.rooms).length });
});

for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, () => {
    log.info({ msg: `${signal} received, shutting down` });
    server.close(() => {
      pool.end().finally(() => process.exit(0));
    });
    setTimeout(() => process.exit(0), 5000).unref();
  });
}
