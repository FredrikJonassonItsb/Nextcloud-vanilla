import test from 'node:test';
import assert from 'node:assert/strict';
import {
  parseTalkPayload,
  parseCommand,
  resolveRoute,
  agentCodeFor,
  agentCodeForUid,
  brainLabel,
} from '../src/route.js';

function talkPayload(overrides = {}) {
  return {
    type: 'Create',
    actor: { type: 'Person', id: 'users/rebecca', name: 'Rebecca' },
    object: {
      type: 'Note',
      id: '4711',
      name: 'message',
      content: JSON.stringify({ message: 'Sarah mentioned she is thinking about consulting', parameters: {} }),
      mediaType: 'text/markdown',
    },
    target: { type: 'Collection', id: 'tok-reb', name: 'Reb minne' },
    ...overrides,
  };
}

const rooms = {
  'tok-reb': { brain: 'reb', botEnv: 'REB', mode: 'personal' },
  'tok-team': { brain: 'team', botEnv: 'REB', mode: 'team' },
};

test('chat message parses to normalized fields', () => {
  const msg = parseTalkPayload(talkPayload());
  assert.equal(msg.skip, undefined);
  assert.equal(msg.roomToken, 'tok-reb');
  assert.equal(msg.messageId, '4711');
  assert.equal(msg.actorUid, 'rebecca');
  assert.match(msg.text, /^Sarah mentioned/);
});

test('bot-authored messages are skipped', () => {
  const msg = parseTalkPayload(talkPayload({ actor: { type: 'Person', id: 'bots/brain', name: 'Reb (agent)' } }));
  assert.equal(msg.skip, true);
});

test('system/edit events (non-Create) are skipped', () => {
  assert.equal(parseTalkPayload(talkPayload({ type: 'Activity' })).skip, true);
  assert.equal(parseTalkPayload(talkPayload({ type: 'Update' })).skip, true);
});

test('empty message content is skipped', () => {
  const p = talkPayload();
  p.object = { ...p.object, content: JSON.stringify({ message: '   ' }) };
  assert.equal(parseTalkPayload(p).skip, true);
});

test('unrouted room resolves to null; routed room normalizes', () => {
  assert.equal(resolveRoute(rooms, 'tok-unknown'), null);
  const r = resolveRoute(rooms, 'tok-team');
  assert.deepEqual(r, { brain: 'team', botEnv: 'REB', mode: 'team' });
});

test('mode defaults to personal for anything but "team"', () => {
  const r = resolveRoute({ t: { brain: 'ada', botEnv: 'ADA', mode: 'weird' } }, 't');
  assert.equal(r.mode, 'personal');
});

test('command grammar: !queue and !status only', () => {
  assert.deepEqual(parseCommand('!queue fix the wiki'), { cmd: 'queue', text: 'fix the wiki' });
  assert.deepEqual(parseCommand('!queue'), { cmd: 'queue', text: '' });
  assert.deepEqual(parseCommand('!status'), { cmd: 'status', agent: null });
  assert.deepEqual(parseCommand('!status atlas'), { cmd: 'status', agent: 'atlas' });
  assert.deepEqual(parseCommand('!STATUS Atlas'), { cmd: 'status', agent: 'atlas' });
  assert.equal(parseCommand('plain thought'), null);
  assert.equal(parseCommand('!queuex not a command'), null);
});

test('identity map: uid -> agent code per CONTRACTS §1', () => {
  assert.equal(agentCodeForUid('rebecca'), 'reb-claude');
  assert.equal(agentCodeForUid('fredrik'), 'atlas-claude');
  assert.equal(agentCodeForUid('sandra'), 'ada-claude');
  assert.equal(agentCodeForUid('mattias'), 'marvin-claude');
  assert.equal(agentCodeForUid('someone-else'), null);
});

test('agentCodeFor accepts short names and full codes, rejects unknowns', () => {
  assert.equal(agentCodeFor('reb'), 'reb-claude');
  assert.equal(agentCodeFor('atlas-claude'), 'atlas-claude');
  assert.equal(agentCodeFor('team'), null);
  assert.equal(agentCodeFor('hacker'), null);
});

test('brain labels are Swedish', () => {
  assert.equal(brainLabel('team'), 'teamhjärnan');
  assert.equal(brainLabel('reb'), 'Rebs hjärna');
});
