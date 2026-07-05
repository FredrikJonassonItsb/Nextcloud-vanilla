// End-to-end handler tests with all IO faked: HMAC gate, dedupe, firewall,
// ingest-before-reply ordering, brain 422, transient-failure claim release,
// reply failure tolerance, !queue and !status.
import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { createWebhookHandler } from '../src/handler.js';
import { createDedupe } from '../src/dedupe.js';
import { createFirewall, compilePatterns } from '../src/firewall.js';
import { formatLedger } from '../src/engine.js';

const SECRET = 's'.repeat(40);
const ROOMS = {
  'tok-reb': { brain: 'reb', botEnv: 'REB', mode: 'personal' },
  'tok-team': { brain: 'team', botEnv: 'REB', mode: 'team' },
};

function makeBody({ text = 'a thought', id = '100', room = 'tok-reb', actor = 'users/rebecca', type = 'Create' } = {}) {
  return JSON.stringify({
    type,
    actor: { type: 'Person', id: actor, name: 'X' },
    object: { type: 'Note', id, name: 'message', content: JSON.stringify({ message: text }) },
    target: { type: 'Collection', id: room, name: 'room' },
  });
}

function headersFor(body, secret = SECRET) {
  const random = 'n0nce'.repeat(13);
  return {
    'x-nextcloud-talk-random': random,
    'x-nextcloud-talk-signature': createHmac('sha256', secret).update(random).update(body).digest('hex'),
  };
}

function fakeDedupeQuery() {
  const rows = new Map();
  return async (sql, params = []) => {
    const key = `${params[0]} ${params[1]}`;
    if (sql.startsWith('CREATE TABLE')) return { rowCount: 0 };
    if (sql.includes('INSERT')) {
      if (rows.has(key)) return { rowCount: 0 };
      rows.set(key, 'pending');
      return { rowCount: 1 };
    }
    if (sql.includes('DELETE')) {
      const del = rows.get(key) === 'pending' && rows.delete(key);
      return { rowCount: del ? 1 : 0 };
    }
    if (sql.includes('UPDATE')) {
      rows.set(key, params[2]);
      return { rowCount: 1 };
    }
    throw new Error('unexpected sql');
  };
}

function build(overrides = {}) {
  const calls = { ingest: [], replies: [], reactions: [], cards: [], ledger: [] };
  const deps = {
    config: { rooms: ROOMS, botSecrets: { REB: SECRET } },
    dedupe: createDedupe(fakeDedupeQuery()),
    firewall: createFirewall(
      compilePatterns([{ id: 'personnummer', pattern: '\\b(19|20)?\\d{6}[-+]?\\d{4}\\b', message: 'personnummer' }]),
    ),
    brains: {
      ingest: async (brain, body) => {
        calls.ingest.push({ brain, body });
        return { ok: true };
      },
    },
    notify: {
      reply: async (botEnv, room, text, replyTo) => calls.replies.push({ botEnv, room, text, replyTo }),
      react: async (botEnv, room, id, reaction) => calls.reactions.push({ reaction }),
    },
    deck: {
      createInboxCard: async (card) => {
        calls.cards.push(card);
        return { cardId: 7, url: 'https://nc/apps/deck/card/7' };
      },
    },
    engine: {
      getLedger: async (agentCode) => {
        calls.ledger.push(agentCode);
        return { state: 'idle', queue: 2 };
      },
    },
    formatLedger,
    log: { info: () => {}, error: () => {} },
    ...overrides,
  };
  return { handle: createWebhookHandler(deps), calls, deps };
}

test('happy path: verified message is ingested then confirmed', async () => {
  const { handle, calls } = build();
  const body = makeBody();
  const res = await handle(body, headersFor(body));
  assert.equal(res.status, 200);
  assert.equal(calls.ingest.length, 1);
  assert.equal(calls.ingest[0].brain, 'reb');
  assert.equal(calls.ingest[0].body.metadata.talk_id, '100');
  assert.equal(calls.ingest[0].body.author, undefined); // personal room: no author override
  assert.deepEqual(calls.reactions, [{ reaction: '👍' }]);
  assert.match(calls.replies[0].text, /^Sparat i Rebs hjärna ✓/);
  assert.equal(calls.replies[0].replyTo, '100');
});

test('bad signature -> 401, nothing ingested', async () => {
  const { handle, calls } = build();
  const body = makeBody();
  const res = await handle(body, headersFor(body, 'wrong'.repeat(8)));
  assert.equal(res.status, 401);
  assert.equal(calls.ingest.length, 0);
  assert.equal(calls.replies.length, 0);
});

test('unrouted room -> 200 ignored before any verification side effects', async () => {
  const { handle, calls } = build();
  const body = makeBody({ room: 'tok-nope' });
  const res = await handle(body, headersFor(body));
  assert.equal(res.status, 200);
  assert.equal(calls.ingest.length, 0);
});

test('duplicate delivery -> single ingest, single confirmation', async () => {
  const { handle, calls } = build();
  const body = makeBody({ id: '200' });
  const h = headersFor(body);
  assert.equal((await handle(body, h)).status, 200);
  const second = await handle(body, h);
  assert.equal(second.status, 200);
  assert.equal(second.body.deduped, true);
  assert.equal(calls.ingest.length, 1);
  assert.equal(calls.replies.length, 1);
});

test('team room sets author to the speaker uid', async () => {
  const { handle, calls } = build();
  const body = makeBody({ room: 'tok-team', actor: 'users/sandra', id: '300' });
  await handle(body, headersFor(body));
  assert.equal(calls.ingest[0].brain, 'team');
  assert.equal(calls.ingest[0].body.author, 'sandra');
  assert.equal(calls.ingest[0].body.metadata.talk_actor, 'sandra');
});

test('firewall hit -> 422, Blockerat reply, nothing leaves the house', async () => {
  const { handle, calls } = build();
  const body = makeBody({ text: 'kunden 19850312-1234 ringde', id: '400' });
  const res = await handle(body, headersFor(body));
  assert.equal(res.status, 422);
  assert.equal(calls.ingest.length, 0);
  assert.match(calls.replies[0].text, /^Blockerat:/);
  // Retry of the same delivery must not double-reply.
  const retry = await handle(body, headersFor(body));
  assert.equal(retry.body.deduped, true);
  assert.equal(calls.replies.length, 1);
});

test('brain 422 -> Blockerat reply with the brain reason', async () => {
  const { handle, calls } = build({
    brains: { ingest: async () => ({ blocked: true, reason: 'PII enligt serverns lista' }) },
  });
  const body = makeBody({ id: '500' });
  const res = await handle(body, headersFor(body));
  assert.equal(res.status, 422);
  assert.match(calls.replies[0].text, /^Blockerat: PII enligt serverns lista/);
});

test('transient ingest failure -> 500 and the claim is released for retry', async () => {
  let fail = true;
  const { handle, calls } = build({
    brains: {
      ingest: async (brain, body) => {
        if (fail) throw new Error('brain down');
        calls.ingest.push({ brain, body });
        return { ok: true };
      },
    },
  });
  const body = makeBody({ id: '600' });
  const res = await handle(body, headersFor(body));
  assert.equal(res.status, 500);
  fail = false;
  const retry = await handle(body, headersFor(body));
  assert.equal(retry.status, 200); // NOT deduped — capture was never lost
  assert.equal(retry.body.stored, true);
});

test('reply failure never fails the capture (ingest happens before reply)', async () => {
  const { handle, calls } = build({
    notify: {
      reply: async () => {}, // notify layer swallows errors by contract; simulate silence
      react: async () => {},
    },
  });
  const body = makeBody({ id: '700' });
  const res = await handle(body, headersFor(body));
  assert.equal(res.status, 200);
  assert.equal(res.body.stored, true);
});

test('!queue captures AND creates an Inbox card with title grammar + link reply', async () => {
  const { handle, calls } = build();
  const body = makeBody({ text: '!queue Uppdatera kunddokumentationen\nmed detaljer', id: '800', actor: 'users/fredrik' });
  const res = await handle(body, headersFor(body));
  assert.equal(res.status, 200);
  assert.equal(calls.ingest.length, 1); // queue also captures
  assert.equal(calls.cards.length, 1);
  assert.equal(calls.cards[0].title, '[inbox][atlas-claude] Uppdatera kunddokumentationen');
  assert.equal(calls.cards[0].assigneeUid, 'fredrik');
  assert.match(calls.replies[0].text, /Sparat i Rebs hjärna ✓/);
  assert.match(calls.replies[0].text, /apps\/deck\/card\/7/);
});

test('deck failure after successful ingest -> degraded reply, still 200', async () => {
  const { handle, calls } = build({
    deck: { createInboxCard: async () => { throw new Error('deck down'); } },
  });
  const body = makeBody({ text: '!queue viktig grej', id: '900' });
  const res = await handle(body, headersFor(body));
  assert.equal(res.status, 200);
  assert.match(calls.replies[0].text, /Kortet kunde inte skapas/);
});

test('!status replies with a compact Swedish ledger line, no capture', async () => {
  const { handle, calls } = build();
  const body = makeBody({ text: '!status ada', id: '1000' });
  const res = await handle(body, headersFor(body));
  assert.equal(res.status, 200);
  assert.equal(calls.ingest.length, 0);
  assert.deepEqual(calls.ledger, ['ada-claude']);
  assert.match(calls.replies[0].text, /Ada — läge: idle · i kö: 2/);
});

test('!status without arg defaults to the sender\'s own agent', async () => {
  const { handle, calls } = build();
  const body = makeBody({ text: '!status', id: '1100', actor: 'users/mattias' });
  await handle(body, headersFor(body));
  assert.deepEqual(calls.ledger, ['marvin-claude']);
});
