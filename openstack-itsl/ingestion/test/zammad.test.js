import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ZammadConnector, idsFromSearch } from '../src/connectors/zammad.js';
import { htmlToText, parseTs, maxIso, isoGte, toBrainPost } from '../src/normalize.js';

function conn() {
  return new ZammadConnector({
    conf: { base_url: 'https://z.itsl.se', expert_names: ['johan'] },
    secrets: {},
    cursors: {},
    raw: {},
    log: { warn() {} },
  });
}

test('normalize: customer=problem, agent=resolution, HTML stripped, expert flag', () => {
  const c = conn();
  const ticket = {
    id: 42, title: 'Login fails', organization: 'KommunX',
    state: 'open', priority: '2 normal', updated_at: '2026-06-01T10:00:00Z',
  };
  const articles = [
    { id: 1, sender: 'customer', from: 'Kund', body: 'Det funkar inte', content_type: 'text/plain', created_at: '2026-06-01T10:00:00Z', internal: false },
    { id: 2, sender: 'agent', from: 'Johan Ström', body: '<p>Starta om miljön</p>', content_type: 'text/html', created_at: '2026-06-01T11:00:00Z', internal: true },
  ];
  const out = [...c._normalize('https://z.itsl.se', ticket, articles, ['bug'], true, 'data/raw/zammad/ticket-42.json')];
  assert.equal(out.length, 2);
  assert.equal(out[0].kind, 'problem');
  assert.equal(out[1].kind, 'resolution');
  assert.equal(out[1].text, 'Starta om miljön'); // HTML stripped
  assert.equal(out[1].author_is_expert, true); // Johan
  assert.equal(out[0].author_is_expert, false);
  assert.equal(out[0].evidence_id, 'zammad-ticket-42-art-1');
  assert.match(out[0].source_url, /#ticket\/zoom\/42$/);
  assert.equal(out[0].customer, 'KommunX');
  assert.equal(out[1].meta.internal, true);
  assert.deepEqual(out[0].meta.tags, ['bug']);
});

test('normalize: internal note excluded when includeInternal=false', () => {
  const c = conn();
  const articles = [{ id: 9, sender: 'agent', from: 'x', body: 'note', content_type: 'text/plain', internal: true }];
  const out = [...c._normalize('https://z', { id: 7, title: 't' }, articles, [], false, 'r')];
  assert.equal(out.length, 0);
});

test('normalize: empty body skipped', () => {
  const c = conn();
  const articles = [{ id: 1, sender: 'agent', from: 'x', body: '   ', content_type: 'text/plain' }];
  const out = [...c._normalize('https://z', { id: 1 }, articles, [], true, 'r')];
  assert.equal(out.length, 0);
});

test('idsFromSearch handles both shapes', () => {
  assert.deepEqual(idsFromSearch({ tickets: [1, 2, 3] }), [1, 2, 3]);
  assert.deepEqual(idsFromSearch({ tickets: [{ id: 5 }, { id: 6 }] }), [5, 6]);
  assert.deepEqual(idsFromSearch([{ id: 8 }, 9]), [8, 9]);
  assert.deepEqual(idsFromSearch(null), []);
});

test('date + html helpers', () => {
  assert.equal(htmlToText('<p>a</p><p>b</p>').replace(/\n+/g, '|'), 'a|b');
  assert.equal(parseTs('2026-06-01T10:00:00Z'), '2026-06-01T10:00:00.000Z');
  assert.equal(parseTs(1717236000), '2024-06-01T10:00:00.000Z');
  assert.equal(maxIso('2026-01-01T00:00:00Z', '2026-06-01T00:00:00Z'), '2026-06-01T00:00:00Z');
  assert.equal(isoGte('2026-06-02T00:00:00Z', '2026-06-01T00:00:00Z'), true);
  assert.equal(isoGte('2026-05-31T00:00:00Z', '2026-06-01T00:00:00Z'), false);
});

test('toBrainPost maps content + metadata', () => {
  const ev = {
    text: 'hej', source: 'zammad', source_url: 'u', timestamp: 't', author: 'Johan',
    author_is_expert: true, kind: 'resolution', evidence_id: 'zammad-1', meta: { ticket_id: 1 },
  };
  const p = toBrainPost(ev);
  assert.equal(p.content, 'hej');
  assert.equal(p.source, 'zammad');
  assert.equal(p.author, 'Johan');
  assert.equal(p.metadata.dedupe_key, 'zammad-1');
  assert.equal(p.metadata.kind, 'resolution');
  assert.equal(p.metadata.ticket_id, 1);
  assert.equal(p.metadata.author_is_expert, true);
});
