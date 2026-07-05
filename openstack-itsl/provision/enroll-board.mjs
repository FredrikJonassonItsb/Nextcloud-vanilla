#!/usr/bin/env node
/**
 * enroll-board.mjs — enroll an ORIGIN Deck board into the agent takeover flow
 * (CONTRACTS §2 origin labels + §3 enrollment endpoint). Idempotent.
 *
 * Given a board id:
 *   1. shares the board with the bot users (edit) so receipts/mirroring work,
 *   2. creates the 3 origin labels: hos-agenten (#1E66D0), agent-fråga (#E6A700),
 *      agent-klar (#2E7D32),
 *   3. registers the board in agent_engine via
 *      PUT /ocs/v2.php/apps/agent_engine/api/v1/boards/{id}/enroll (admin).
 *
 * Deck operations require a user with MANAGE rights on the board (normally the
 * board owner). The enroll call requires an admin — pass --admin-user/--admin-password
 * if the board owner is not an admin.
 *
 * Usage:
 *   node enroll-board.mjs <boardId> [--base https://dev15.hubs.se]
 *        [--user <uid>] [--password <app-password>]
 *        [--admin-user <uid>] [--admin-password <app-password>] [--insecure]
 *   Credential fallbacks: env AE_NC_USER / AE_NC_PASS, AE_ADMIN_USER / AE_ADMIN_PASS.
 */

import process from 'node:process';

const BOTS = ['bot-reb', 'bot-atlas', 'bot-ada', 'bot-marvin', 'bot-engine'];
const ORIGIN_LABELS = [
  ['hos-agenten', '1E66D0'],
  ['agent-fråga', 'E6A700'],
  ['agent-klar', '2E7D32'],
];

const USAGE = `Användning: node enroll-board.mjs <boardId> [--base URL] [--user UID] [--password APP_PW]
            [--admin-user UID] [--admin-password APP_PW] [--insecure]`;

function parseArgs(argv) {
  const args = { base: 'https://dev15.hubs.se' };
  const positional = [];
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    switch (a) {
      case '--base': args.base = argv[++i]; break;
      case '--user': args.user = argv[++i]; break;
      case '--password': args.password = argv[++i]; break;
      case '--admin-user': args.adminUser = argv[++i]; break;
      case '--admin-password': args.adminPassword = argv[++i]; break;
      case '--insecure': process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0'; break;
      case '--help':
      case '-h': console.log(USAGE); process.exit(0); break;
      default:
        if (a.startsWith('--')) { console.error(`Okänd flagga: ${a}\n${USAGE}`); process.exit(1); }
        positional.push(a);
    }
  }
  args.boardId = Number(positional[0]);
  if (!Number.isInteger(args.boardId) || args.boardId <= 0) {
    console.error(`FEL: ange tavlans id som första argument.\n${USAGE}`);
    process.exit(1);
  }
  args.user = args.user ?? process.env.AE_NC_USER;
  args.password = args.password ?? process.env.AE_NC_PASS;
  if (!args.user || !args.password) {
    console.error('FEL: ange --user/--password (eller AE_NC_USER/AE_NC_PASS) — en användare med manage-rätt på tavlan.');
    process.exit(1);
  }
  args.adminUser = args.adminUser ?? process.env.AE_ADMIN_USER ?? args.user;
  args.adminPassword = args.adminPassword ?? process.env.AE_ADMIN_PASS ?? args.password;
  args.base = args.base.replace(/\/+$/, '');
  return args;
}

const cfg = parseArgs(process.argv);

function basicAuth(user, pass) {
  return 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64');
}

async function deck(method, path, body) {
  const res = await fetch(`${cfg.base}/index.php/apps/deck/api/v1.0${path}`, {
    method,
    headers: {
      Authorization: basicAuth(cfg.user, cfg.password),
      'OCS-APIRequest': 'true',
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const text = await res.text();
  if (!res.ok) {
    throw new Error(`${method} ${path} → HTTP ${res.status}: ${text.slice(0, 300)}`);
  }
  return text ? JSON.parse(text) : null;
}

async function main() {
  console.log(`→ Enrollment av tavla ${cfg.boardId} mot ${cfg.base} som ${cfg.user}`);

  const board = await deck('GET', `/boards/${cfg.boardId}`);
  console.log(`  tavla: "${board.title}" (ägare ${board.owner?.uid ?? board.owner})`);

  // 1) Share with bots (edit)
  const aclUids = new Set(
    (board.acl ?? []).map((a) => a.participant?.primaryKey ?? a.participant?.uid ?? a.participant),
  );
  const ownerUid = board.owner?.uid ?? board.owner;
  for (const uid of BOTS) {
    if (uid === ownerUid || aclUids.has(uid)) {
      console.log(`  = ${uid} har redan åtkomst`);
      continue;
    }
    try {
      await deck('POST', `/boards/${cfg.boardId}/acl`, {
        type: 0,
        participant: uid,
        permissionEdit: true,
        permissionShare: false,
        permissionManage: false,
      });
      console.log(`  + delad med ${uid} (edit)`);
    } catch (err) {
      console.warn(`  ! kunde inte dela med ${uid}: ${err.message} (kör occ-provision.sh först?)`);
    }
  }

  // 2) Origin labels (CONTRACTS §2)
  const labels = board.labels ?? [];
  for (const [title, color] of ORIGIN_LABELS) {
    const existing = labels.find((l) => l.title === title);
    if (existing) {
      console.log(`  = label "${title}" finns (id ${existing.id})`);
      continue;
    }
    const label = await deck('POST', `/boards/${cfg.boardId}/labels`, { title, color });
    console.log(`  + label "${title}" skapad (id ${label.id})`);
  }

  // 3) Register in agent_engine (OCS, admin)
  const res = await fetch(
    `${cfg.base}/ocs/v2.php/apps/agent_engine/api/v1/boards/${cfg.boardId}/enroll`,
    {
      method: 'PUT',
      headers: {
        Authorization: basicAuth(cfg.adminUser, cfg.adminPassword),
        'OCS-APIRequest': 'true',
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({}),
    },
  );
  const text = await res.text();
  if (res.status === 404) {
    console.error('FEL: agent_engine svarar 404 — är appen deployad och aktiverad? (provision/deploy-app.sh)');
    process.exit(2);
  }
  if (!res.ok) {
    console.error(`FEL: enroll → HTTP ${res.status}: ${text.slice(0, 300)}`);
    process.exit(1);
  }
  let meta = {};
  try { meta = JSON.parse(text)?.ocs?.meta ?? {}; } catch { /* non-JSON body tolerated */ }
  console.log(`  ✓ tavla ${cfg.boardId} registrerad i agent_engine (OCS ${meta.statuscode ?? res.status})`);
  console.log('✓ Enrollment klar (idempotent — säker att köra igen).');
}

main().catch((err) => {
  console.error(`FEL: ${err.message}`);
  process.exit(1);
});
