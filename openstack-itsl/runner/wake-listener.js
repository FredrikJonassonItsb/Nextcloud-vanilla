#!/usr/bin/env node
/**
 * wake-listener.js — HMAC-verified push endpoint for the runner (CONTRACTS §3, §7)
 *
 * agent_engine POSTs http://10.43.51.62:8791/wake/{agentCode} with headers
 *   X-AE-Timestamp: <unix seconds>
 *   X-AE-Signature: hex(hmac_sha256(ENGINE_PUSH_SECRET, timestamp + "." + agentCode))
 * Tolerance: ±300 s. No payload — the runner pulls its own work via
 * GET /queue/{agentCode} inside run-agent.sh.
 *
 * On a valid wake the listener spawns bin/run-agent.sh <agentCode> detached;
 * the per-agent flock inside run-agent.sh makes cron-vs-wake collisions safe,
 * and a 60 s in-process debounce absorbs event fan-out bursts (BYGGPLAN §5.2).
 *
 * Also serves GET /healthz for the container HEALTHCHECK.
 *
 * Node stdlib only — no npm dependencies.
 */

'use strict';

const http = require('node:http');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const path = require('node:path');

const PORT = 8791;
const TOLERANCE_S = 300;
const DEBOUNCE_MS = 60_000;
const RUN_AGENT = path.join(__dirname, 'bin', 'run-agent.sh');

const SECRET = process.env.ENGINE_PUSH_SECRET;
if (!SECRET) {
  console.error(ts() + ' wake-listener: FATAL — ENGINE_PUSH_SECRET is not set');
  process.exit(1);
}

// CONTRACTS §1: the four agent codes. Overridable only for test rigs.
const AGENT_CODES = new Set(
  (process.env.RUNNER_AGENT_CODES || 'reb-claude,atlas-claude,ada-claude,marvin-claude')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
);

/** last accepted wake per agent, for debounce */
const lastWake = new Map();

function ts() {
  return new Date().toISOString();
}

function log(msg) {
  console.log(`${ts()} wake-listener: ${msg}`);
}

function reply(res, status, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(body),
  });
  res.end(body);
}

/**
 * Verify X-AE-Timestamp / X-AE-Signature per CONTRACTS §3.
 * Returns null on success, otherwise a short rejection reason.
 */
function verify(agentCode, timestampHeader, signatureHeader) {
  if (!timestampHeader || !signatureHeader) return 'missing X-AE-Timestamp or X-AE-Signature';
  if (!/^\d{1,12}$/.test(timestampHeader)) return 'malformed timestamp';

  const tsSec = Number(timestampHeader);
  const nowSec = Math.floor(Date.now() / 1000);
  if (Math.abs(nowSec - tsSec) > TOLERANCE_S) return 'timestamp outside ±300 s tolerance';

  const expected = crypto
    .createHmac('sha256', SECRET)
    .update(`${timestampHeader}.${agentCode}`)
    .digest('hex');

  const got = String(signatureHeader).toLowerCase();
  if (got.length !== expected.length) return 'bad signature';
  const a = Buffer.from(expected, 'utf8');
  const b = Buffer.from(got, 'utf8');
  if (!crypto.timingSafeEqual(a, b)) return 'bad signature';
  return null;
}

function triggerRun(agentCode) {
  const child = spawn(RUN_AGENT, [agentCode], {
    detached: true,
    stdio: ['ignore', 'inherit', 'inherit'],
    env: process.env,
  });
  child.on('error', (err) => {
    log(`ERROR spawning run-agent.sh for ${agentCode}: ${err.message}`);
  });
  child.unref();
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');

  if (req.method === 'GET' && url.pathname === '/healthz') {
    reply(res, 200, { ok: true, service: 'runner-wake-listener' });
    return;
  }

  const m = url.pathname.match(/^\/wake\/([a-z0-9-]+)$/);
  if (!m) {
    reply(res, 404, { error: 'not found' });
    return;
  }
  if (req.method !== 'POST') {
    reply(res, 405, { error: 'method not allowed' });
    return;
  }

  const agentCode = m[1];
  if (!AGENT_CODES.has(agentCode)) {
    log(`wake rejected: unknown agent code "${agentCode}"`);
    reply(res, 404, { error: 'unknown agent code' });
    return;
  }

  const reason = verify(
    agentCode,
    req.headers['x-ae-timestamp'],
    req.headers['x-ae-signature']
  );
  if (reason) {
    log(`wake rejected for ${agentCode}: ${reason}`);
    reply(res, 401, { error: reason });
    return;
  }

  // Drain any request body (there should be none per contract).
  req.resume();

  const now = Date.now();
  const last = lastWake.get(agentCode) || 0;
  if (now - last < DEBOUNCE_MS) {
    log(`wake accepted for ${agentCode} — debounced (last trigger ${Math.round((now - last) / 1000)}s ago)`);
    reply(res, 202, { queued: false, debounced: true });
    return;
  }
  lastWake.set(agentCode, now);

  log(`wake accepted for ${agentCode} — triggering run`);
  triggerRun(agentCode);
  reply(res, 202, { queued: true });
});

server.listen(PORT, '0.0.0.0', () => {
  log(`listening on :${PORT} (agents: ${[...AGENT_CODES].join(', ')})`);
});

for (const sig of ['SIGTERM', 'SIGINT']) {
  process.on(sig, () => {
    log(`received ${sig}, shutting down`);
    server.close(() => process.exit(0));
    // In-flight claude runs are children of init/cron, not of us — safe to go.
    setTimeout(() => process.exit(0), 3000).unref();
  });
}
