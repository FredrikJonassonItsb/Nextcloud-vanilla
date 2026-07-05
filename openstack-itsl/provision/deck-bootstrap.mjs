#!/usr/bin/env node
/**
 * deck-bootstrap.mjs — idempotent bootstrap of the Deck board "Agent Engine"
 * on Hubs, per CONTRACTS §2. Runs from Windows (Node 18+) against the Deck REST
 * API as bot-engine (board owner) using its app password.
 *
 * Creates (matching by title — NEVER duplicates on re-run):
 *   - board "Agent Engine" (owner bot-engine)
 *   - 7 stacks in CONTRACTS order
 *   - labels: agent-instructions (#B22222) + the v1 working labels
 *   - ACL: all humans (edit) + all agent bots (edit)
 *   - the 4 standing cards with full bodies (label agent-instructions)
 *
 * Writes state/bootstrap.json {boardId, stackIds, labelId, cardIds} locally and
 * uploads it to /opt/openstack/state/bootstrap.json on the server (scp + sudo).
 *
 * Usage:
 *   node deck-bootstrap.mjs [--base https://dev15.hubs.se] [--user bot-engine]
 *        [--password <app-password>] [--ssh ubuntu@10.43.51.62]
 *        [--no-upload] [--force-body] [--insecure]
 *   Password fallback: env BOT_APP_PASSWORD_ENGINE (from /opt/openstack/.env).
 */

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import process from 'node:process';

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));

const HUMANS = ['rebecca', 'fredrik', 'sandra', 'mattias'];
const AGENT_BOTS = ['bot-reb', 'bot-atlas', 'bot-ada', 'bot-marvin'];
const BOARD_TITLE = 'Agent Engine';
const STACKS = [
  'Inbox',
  'Standing',
  'Agent Todo',
  'Agent Working',
  'Agent Needs Input',
  'Agent Review',
  'Agent Done',
];
// agent-instructions per CONTRACTS §2; the rest is the complete v1 label list (BYGGPLAN §3.1).
const LABELS = [
  ['agent-instructions', 'B22222'],
  ['blocked', 'E6A700'],
  ['human-hold', 'D35400'],
  ['delegated', '7E57C2'],
  ['needs-enrichment', '999999'],
];

const CARD_TITLES = {
  coreContext: '[agent instructions][all agents][standing_skill] Install ITSL Agent Engine core context v1',
  ledger: '[agent instructions][all agents][standing_status] Agent Engine status ledger',
  routingMap: '[agent instructions][all agents][standing_routing] Agent routing map v1',
  skillDirectory: '[agent instructions][all agents][standing_skill] Optional standing skill directory',
};

// ── Card bodies (Swedish prose, English protocol tokens) ─────────────────────

const LEDGER_BODY = `Statusliggare för Agent Engine.

Varje agent äger exakt EN kommentar på detta kort. Den börjar exakt \`AGENT STATUS\` och
uppdateras PÅ PLATS varje körning via
\`PUT /ocs/v2.php/apps/agent_engine/api/v1/ledger/{agentCode}\` — aldrig nya
heartbeat-kommentarer. Kvitton och statusar hålls ≤900 tecken (Deck-gränsen är 1000).

Format (verbatim):

\`\`\`
AGENT STATUS
Agent: <agent-code>
Human/operator: <name or unknown>
Runtime: <Claude Code (headless runner) | Claude Code (interactive) | other>
Automation: deck-queue-runner v1
Automation state: <installed | manual-required | blocked | paused>
Last heartbeat: <ISO8601 timestamp>
Last queue result: <checking | none | observed AE-123 | claimed AE-123 | completed AE-123 | blocked AE-123 | holding AE-123 | resumed AE-123 | failed AE-123>
Last successful run: <ISO8601 timestamp or unknown>
Local context: <engine version>; <routing map version>
Optional skills: <none or skill-id@version subscribed>
Notes: <none or short blocker>
\`\`\`

Semantik: \`blocked AE-n\` = svaret hör hemma på engine-kortet · \`holding AE-n\` = svaret hör
hemma i människans egen session · \`completed AE-n\` endast när uppgiften faktiskt är klar.`;

const ROUTING_BODY = `## Agent routing map v1

| Människa (NC-uid) | Agent | Agentkod | Bot-NC-användare | Visningsnamn |
|---|---|---|---|---|
| rebecca | Reb | \`reb-claude\` | \`bot-reb\` | Reb (agent) |
| fredrik | Atlas | \`atlas-claude\` | \`bot-atlas\` | Atlas (agent) |
| sandra | Ada | \`ada-claude\` | \`bot-ada\` | Ada (agent) |
| mattias | Marvin | \`marvin-claude\` | \`bot-marvin\` | Marvin (agent) |
| — (system) | — | — | \`bot-engine\` | Agent Engine |

Rebecca — Deck-assignee: rebecca — Agentkod: reb-claude
  Routa till Rebecca för: <fastställs på M0-kickoff — HÅRD GRIND före M7>

Fredrik — Deck-assignee: fredrik — Agentkod: atlas-claude
  Routa till Fredrik för: plattformsarkitektur, hubs_arende/hubs_start-backend,
  deploys via itsl CLI, dev15-ops, agent-stackens drift

Sandra — Deck-assignee: sandra — Agentkod: ada-claude
  Routa till Sandra för: <fastställs på M0-kickoff>

Mattias — Deck-assignee: mattias — Agentkod: marvin-claude
  Routa till Mattias för: <fastställs på M0-kickoff>

Regler (verbatim ur Open Engine):
- Tilldela korsagent-arbete till MÄNNISKAN som äger målagenten, aldrig dig själv.
- Om målagenten inte är online i statusliggaren: säg det innan du litar på överlämningen.
- Mänskligt godkännande krävs för publicering, deploys och kundvända ändringar.
- Skriv routade kort så målagenten kan läsa dem kallt (fulla kortmallen).`;

const SKILL_DIRECTORY_BODY = `## Optional standing skill directory

Katalog över valbara delade förmågor. Regler (Open Engine, katalog utan auto-install):

- Standard-setup registrerar bara VAR katalogen finns — inget installeras, inga verktyg
  aktiveras, ingen ny auktoritet ges.
- En kanonisk Standing-post per optional skill: syfte, runtime-stöd, install-källa, version,
  update-kanal, godkännanderegler, kvittomallar.
- Bläddring sker på människans begäran: agenten läser katalogen och sammanfattar.
  Första install/adaption kräver mänskligt godkännande i den runtimens EGEN session.
- Godkännande skapar prenumeration: samma godkännande täcker buggfixar och
  same-scope-uppdateringar för den skillen i den runtimen. Scope-utökning frågar igen
  (nya permissions, nya externa handlingar, nya verktyg, annan runtime-gräns).
- Rutinkörningar kontrollerar ENDAST redan prenumererade skills för
  same-scope-uppdateringar — aldrig bläddra eller installera nytt.
- Kvitton på det kanoniska skill-kortet: AGENT SKILL SUBSCRIBED / AGENT SKILL INSTALLED /
  AGENT SKILL UPDATED / AGENT SKILL DECLINED.

## Katalog v1 (poster läggs till som egna Standing-kort; innehållet byggs efter v1)
- hubs-deploy-runbook — itsl-CLI-livscykeln, dev15-ops; prod = draft-only (människan kör)
- nextcloud-app-dev — ITSL:s appkonventioner (OCS, Mapper, Vue 2.7)
- testing-runbook-creator — QA-sessioner appendar till docs/testing-runbook.md
- handover-writer — ITSL:s HANDOVER-*.md-format
- meeting-synthesis — svenska möten → beslut/actions/öppna frågor
- brain-dump-processor — "process this" → per-idé-capture till egen hjärna
- session-to-skill-extractor — aiception; utkast → PR + Review-kort, aldrig tyst
- stakeholder-update — veckostatus ur engine_meta.runs + kvitton
- agent-maintenance-loop — 6-stegsauditen, triggerstyrd (aldrig kalenderstyrd)`;

function coreContextBody(ids) {
  return `## Vad detta är
Agent Engine är teamets delade arbetskö: fyra namngivna agenter (Reb, Atlas, Ada, Marvin)
plockar kort från denna tavla via sina runners och lämnar kvitton som kommentarer (postade
av respektive bot-användare). Detta kort är setup-instruktionen för varje runtime —
interaktiv Claude Code och headless runner.

## Installation (per runtime)
1. Klona stack-repot och installera kärnskillsen från \`skills/core/\`:
   \`open-agent-engine\`, \`deck-receipts\`, \`card-enricher\`, \`auto-capture\`,
   \`hubs-local-tests\` → kör \`scripts/sync-skills.sh\` (interaktivt: till
   \`~/.claude/skills/\`; runnern bakar in dem i sin image).
2. Lägg identitetsblocket i \`~/.claude/CLAUDE.md\`: agentkod, brain-routing (egen vs team),
   capture-rumsvanan, PII-regeln, Deck-konventionerna, godkännandegränserna.
3. Verifiera anslutningen: \`GET /ocs/v2.php/apps/agent_engine/api/v1/queue/<agentkod>\`
   svarar 200 med bot-användarens app-lösenord.
4. Kör smoke: hello-world-kort → AGENT CLAIMED → AGENT DONE → liggaren \`completed AE-n\`.
5. Posta kvittot \`AGENT AUTOMATION READY\` på DETTA kort när install + smoke är gröna.

## Nyckelkort
- Statusliggare: AE-${ids.ledger}
- Routingkarta: AE-${ids.routingMap}
- Optional skill directory: AE-${ids.skillDirectory}

## Brain-endpoints (nycklar bor i .env/keychain — ALDRIG på tavlan)
- https://10.43.51.62:8843/reb/mcp · /atlas/mcp · /ada/mcp · /marvin/mcp · /team/mcp

## Körbarhet (eligibility)
Ett kort är körbart för \`<agentkod>\` omm: stack = Agent Todo OCH label
\`agent-instructions\` OCH titeln börjar \`[agent instructions]\` OCH bracket 2 = agentkoden
OCH assignee = agentens människa (routingkartan). Äldst först. Exakt ETT kort per körning.
Titelgrammatik: \`[agent instructions][<agentkod>][task] <titel>\` ·
\`[agent instructions][all agents][standing_skill|standing_status|standing_routing] <titel>\`.

## Kvitton (exakta tokens, alltid första raden i kommentaren; ≤900 tecken)
AGENT CLAIMED · AGENT DONE · AGENT BLOCKED · AGENT UNBLOCKED · AGENT HUMAN HOLD ·
AGENT HUMAN ANSWERED · AGENT RESUMED · AGENT FAILED · AGENT APPLIED ·
AGENT SKILL SUBSCRIBED · AGENT SKILL INSTALLED · AGENT SKILL UPDATED ·
AGENT SKILL DECLINED · AGENT FOLLOW-UP · AGENT STATUS.
ITSL-tillägg: relä-kommentarer på URSPRUNGSKORT prefixas \`⇄ \`.

## Boundaries (verbatim)
"Never publish, email, post outside receipts/capture confirmations, deploy, delete,
change billing, change credentials, or make outward-facing changes unless the card
explicitly grants that approval."
Fråga-först dessutom: alla deploys, occ mot live-instans, merges till main,
credential-/faktureringsändringar, destruktiva dataoperationer, installation av optional
skills. Tystnad är inte samtycke.

## Privacy
Tavlan är teamsynlig. Secrets, kundkontext och privata skill-kroppar bor i lokala
kontextfiler — aldrig här. PII (personnummer, ärende-id, BankID-nr) får aldrig förekomma
på kort eller i kvitton — PII-brandväggen svarar 422.`;
}

// ── CLI ──────────────────────────────────────────────────────────────────────

const USAGE = `Användning: node deck-bootstrap.mjs [--base URL] [--user UID] [--password APP_PW]
            [--ssh HOST] [--no-upload] [--force-body] [--insecure]
App-lösenord: --password eller env BOT_APP_PASSWORD_ENGINE.`;

function parseArgs(argv) {
  const args = {
    base: 'https://dev15.hubs.se',
    user: 'bot-engine',
    ssh: 'ubuntu@10.43.51.62',
    upload: true,
    forceBody: false,
  };
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    switch (a) {
      case '--base': args.base = argv[++i]; break;
      case '--user': args.user = argv[++i]; break;
      case '--password': args.password = argv[++i]; break;
      // Resolved human uids to share the board with. On BankID instances the
      // NC uid is a personnummer (e.g. 197411040293), NOT the canonical name —
      // sharing with the canonical name creates a phantom ACL that can never be
      // a card assignee (see docs/DEV15-FACTS.md). Pass the real uids here.
      case '--humans': args.humans = argv[++i].split(',').map((s) => s.trim()).filter(Boolean); break;
      case '--ssh': args.ssh = argv[++i]; break;
      case '--no-upload': args.upload = false; break;
      case '--force-body': args.forceBody = true; break;
      case '--insecure': process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0'; break;
      case '--help':
      case '-h': console.log(USAGE); process.exit(0); break;
      default: console.error(`Okänd flagga: ${a}\n${USAGE}`); process.exit(1);
    }
  }
  args.password = args.password ?? process.env.BOT_APP_PASSWORD_ENGINE;
  if (!args.password) {
    console.error('FEL: ange --password eller env BOT_APP_PASSWORD_ENGINE (bot-engines app-lösenord).');
    process.exit(1);
  }
  args.base = args.base.replace(/\/+$/, '');
  return args;
}

const cfg = parseArgs(process.argv);
const AUTH = 'Basic ' + Buffer.from(`${cfg.user}:${cfg.password}`).toString('base64');

async function deck(method, path, body) {
  const res = await fetch(`${cfg.base}/index.php/apps/deck/api/v1.0${path}`, {
    method,
    headers: {
      Authorization: AUTH,
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

// ── Main ─────────────────────────────────────────────────────────────────────

async function main() {
  console.log(`→ Deck-bootstrap mot ${cfg.base} som ${cfg.user}`);

  // 1) Board (match by title, never duplicate)
  const boards = await deck('GET', '/boards');
  let board = boards.find((b) => b.title === BOARD_TITLE && !b.deletedAt);
  if (board) {
    console.log(`  = tavla "${BOARD_TITLE}" finns (id ${board.id})`);
  } else {
    board = await deck('POST', '/boards', { title: BOARD_TITLE, color: '0082C9' });
    console.log(`  + tavla "${BOARD_TITLE}" skapad (id ${board.id})`);
  }
  const boardId = board.id;
  const details = await deck('GET', `/boards/${boardId}`);

  // 2) Stacks in CONTRACTS order
  const existingStacks = await deck('GET', `/boards/${boardId}/stacks`);
  const stackIds = {};
  const stackCards = {}; // stackId -> cards[]
  for (const s of existingStacks) {
    stackCards[s.id] = s.cards ?? [];
  }
  for (let i = 0; i < STACKS.length; i++) {
    const title = STACKS[i];
    let stack = existingStacks.find((s) => s.title === title);
    if (stack) {
      console.log(`  = stack "${title}" finns (id ${stack.id})`);
    } else {
      stack = await deck('POST', `/boards/${boardId}/stacks`, { title, order: i });
      stackCards[stack.id] = [];
      console.log(`  + stack "${title}" skapad (id ${stack.id})`);
    }
    stackIds[title] = stack.id;
  }

  // 3) Labels
  const labels = details.labels ?? [];
  const labelIds = {};
  for (const [title, color] of LABELS) {
    let label = labels.find((l) => l.title === title);
    if (label) {
      console.log(`  = label "${title}" finns (id ${label.id})`);
    } else {
      label = await deck('POST', `/boards/${boardId}/labels`, { title, color });
      console.log(`  + label "${title}" skapad (id ${label.id})`);
    }
    labelIds[title] = label.id;
  }
  const labelId = labelIds['agent-instructions'];

  // 4) ACL: humans (edit) + agent bots (edit). Prefer resolved --humans uids
  // (BankID instances use personnummer as uid); fall back to canonical names
  // only when nothing was passed (a phantom ACL is harmless — Deck skips
  // non-existent participants — but such a user can never be a card assignee).
  const humans = (cfg.humans && cfg.humans.length) ? cfg.humans : HUMANS;
  const acl = details.acl ?? [];
  const aclUids = new Set(
    acl.map((a) => a.participant?.primaryKey ?? a.participant?.uid ?? a.participant),
  );
  for (const uid of [...humans, ...AGENT_BOTS]) {
    if (uid === cfg.user) continue; // owner
    if (aclUids.has(uid)) {
      console.log(`  = delning med ${uid} finns`);
      continue;
    }
    try {
      await deck('POST', `/boards/${boardId}/acl`, {
        type: 0,
        participant: uid,
        permissionEdit: true,
        permissionShare: false,
        permissionManage: false,
      });
      console.log(`  + delad med ${uid} (edit)`);
    } catch (err) {
      console.warn(`  ! kunde inte dela med ${uid}: ${err.message} (saknas användaren? kör occ-provision.sh)`);
    }
  }

  // 5) Standing cards — match by title across the whole board (never duplicate)
  const cardByTitle = new Map();
  for (const [sid, cards] of Object.entries(stackCards)) {
    for (const c of cards) cardByTitle.set(c.title, { card: c, stackId: Number(sid) });
  }
  const standingId = stackIds['Standing'];
  const cardIds = {};
  const created = new Set();

  async function ensureCard(key, order, description) {
    const title = CARD_TITLES[key];
    const hit = cardByTitle.get(title);
    if (hit) {
      console.log(`  = kort finns: ${title} (AE-${hit.card.id})`);
      cardIds[key] = hit.card.id;
      return hit;
    }
    const card = await deck('POST', `/boards/${boardId}/stacks/${standingId}/cards`, {
      title,
      type: 'plain',
      order,
      description,
    });
    console.log(`  + kort skapat: ${title} (AE-${card.id})`);
    cardIds[key] = card.id;
    created.add(key);
    const entry = { card, stackId: standingId };
    cardByTitle.set(title, entry);
    return entry;
  }

  // Cards without id references first, so the core-context card can embed real ids.
  await ensureCard('ledger', 1, LEDGER_BODY);
  await ensureCard('routingMap', 2, ROUTING_BODY);
  await ensureCard('skillDirectory', 3, SKILL_DIRECTORY_BODY);
  await ensureCard('coreContext', 0, ''); // body set below when all ids are known

  const bodies = {
    ledger: LEDGER_BODY,
    routingMap: ROUTING_BODY,
    skillDirectory: SKILL_DIRECTORY_BODY,
    coreContext: coreContextBody(cardIds),
  };

  async function updateBody(key) {
    const { card, stackId } = cardByTitle.get(CARD_TITLES[key]);
    await deck('PUT', `/boards/${boardId}/stacks/${stackId}/cards/${card.id}`, {
      title: card.title,
      type: 'plain',
      order: card.order ?? 0,
      owner: card.owner?.uid ?? card.owner ?? cfg.user,
      description: bodies[key],
      duedate: card.duedate ?? null,
    });
  }

  // Newly created cards get their canonical body (core context needs the id backfill);
  // existing cards keep human edits unless --force-body.
  for (const key of Object.keys(CARD_TITLES)) {
    if (created.has(key) || cfg.forceBody) {
      await updateBody(key);
      console.log(`  ✓ kortbeskrivning satt: ${CARD_TITLES[key]}`);
    } else {
      const desc = cardByTitle.get(CARD_TITLES[key]).card.description ?? '';
      if (key === 'coreContext' && !desc.includes(`AE-${cardIds.ledger}`)) {
        console.warn('  ! core-context-kortets nyckelkorts-id:n ser inaktuella ut — kör med --force-body för att skriva om kanoniska kroppar');
      }
    }
  }

  // 6) Label agent-instructions on all four standing cards
  for (const key of Object.keys(CARD_TITLES)) {
    const { card, stackId } = cardByTitle.get(CARD_TITLES[key]);
    const hasLabel = (card.labels ?? []).some((l) => l.id === labelId);
    if (hasLabel) {
      console.log(`  = label agent-instructions redan på AE-${card.id}`);
      continue;
    }
    try {
      await deck('PUT', `/boards/${boardId}/stacks/${stackId}/cards/${card.id}/assignLabel`, {
        labelId,
      });
      console.log(`  + label agent-instructions satt på AE-${card.id}`);
    } catch (err) {
      console.warn(`  ! kunde inte sätta label på AE-${card.id}: ${err.message}`);
    }
  }

  // 7) bootstrap.json — locally + upload to the server
  const state = {
    generatedAt: new Date().toISOString(),
    base: cfg.base,
    boardId,
    stackIds,
    labelId,
    labelIds,
    cardIds,
  };
  const stateDir = join(SCRIPT_DIR, 'state');
  mkdirSync(stateDir, { recursive: true });
  const localPath = join(stateDir, 'bootstrap.json');
  writeFileSync(localPath, JSON.stringify(state, null, 2) + '\n', 'utf8');
  console.log(`  ✓ skrivet: ${localPath}`);

  if (cfg.upload) {
    const remoteTmp = '/tmp/ae-bootstrap.json';
    let r = spawnSync('scp', ['-o', 'BatchMode=yes', localPath, `${cfg.ssh}:${remoteTmp}`], {
      stdio: 'inherit',
    });
    if (r.status !== 0) throw new Error('scp av bootstrap.json misslyckades');
    r = spawnSync(
      'ssh',
      [
        '-o', 'BatchMode=yes', cfg.ssh,
        `sudo install -D -m 0644 ${remoteTmp} /opt/openstack/state/bootstrap.json && rm -f ${remoteTmp}`,
      ],
      { stdio: 'inherit' },
    );
    if (r.status !== 0) throw new Error('fjärrinstallation av bootstrap.json misslyckades');
    console.log(`  ✓ uppladdat: ${cfg.ssh}:/opt/openstack/state/bootstrap.json`);
    console.log('  → kör om provision/occ-provision.sh så att agent_engine board_id sätts.');
  } else {
    console.log('  (uppladdning överhoppad: --no-upload)');
  }

  console.log('');
  console.log('=== RESULTAT ===');
  console.log(`tavla:  ${boardId} ("${BOARD_TITLE}")`);
  console.log(`stackar: ${STACKS.map((s) => `${s}=${stackIds[s]}`).join(' · ')}`);
  console.log(`label agent-instructions: ${labelId}`);
  for (const [k, v] of Object.entries(cardIds)) console.log(`kort ${k}: AE-${v}`);
  console.log('✓ Deck-bootstrap klar (idempotent — säker att köra igen).');
}

main().catch((err) => {
  console.error(`FEL: ${err.message}`);
  process.exit(1);
});
