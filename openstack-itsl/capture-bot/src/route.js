// Talk webhook payload parsing, room -> brain routing and command grammar.
// Payloads are Activity Streams 2.0 (actor / object / target).

// CONTRACTS §1 — routing map v1 (NC uid -> agent short name).
export const IDENTITY_MAP = Object.freeze({
  rebecca: 'reb',
  fredrik: 'atlas',
  sandra: 'ada',
  mattias: 'marvin',
});

export const BRAIN_LABELS = Object.freeze({
  reb: 'Rebs hjärna',
  atlas: 'Atlas hjärna',
  ada: 'Adas hjärna',
  marvin: 'Marvins hjärna',
  team: 'teamhjärnan',
});

/** Agent code per CONTRACTS §1: short name + "-claude". */
export function agentCodeFor(shortName) {
  if (!shortName) return null;
  const s = String(shortName).toLowerCase().replace(/-claude$/, '');
  if (!['reb', 'atlas', 'ada', 'marvin'].includes(s)) return null;
  return `${s}-claude`;
}

/** Agent code for a human NC uid, or null when unknown. */
export function agentCodeForUid(uid) {
  return agentCodeFor(IDENTITY_MAP[String(uid || '').toLowerCase()]);
}

export function brainLabel(brain) {
  return BRAIN_LABELS[brain] || `hjärnan ${brain}`;
}

/**
 * Parse an inbound Talk bot webhook payload into a normalized message,
 * or a { skip: true, reason } marker for events the capture flow ignores.
 */
export function parseTalkPayload(payload) {
  if (!payload || typeof payload !== 'object') {
    return { skip: true, reason: 'empty payload' };
  }
  // New chat messages arrive as "Create"; edits ("Update"), system messages
  // ("Activity") and reaction/join events are not capture input.
  if (payload.type !== 'Create') {
    return { skip: true, reason: `event type ${payload.type || 'unknown'} ignored` };
  }
  const object = payload.object || {};
  if (object.name !== 'message') {
    return { skip: true, reason: 'not a chat message object' };
  }
  const actorId = String(payload.actor?.id ?? '');
  if (actorId.startsWith('bots/')) {
    return { skip: true, reason: 'bot-authored message ignored' };
  }
  let text = '';
  try {
    const content = JSON.parse(String(object.content ?? '{}'));
    text = String(content.message ?? '').trim();
  } catch {
    return { skip: true, reason: 'unparseable message content' };
  }
  if (!text) {
    return { skip: true, reason: 'empty message' };
  }
  const roomToken = String(payload.target?.id ?? '');
  const messageId = String(object.id ?? '');
  if (!roomToken || !messageId) {
    return { skip: true, reason: 'missing room token or message id' };
  }
  return {
    roomToken,
    roomName: String(payload.target?.name ?? ''),
    messageId,
    // actor.id is "users/<uid>" (or "guests/<sha>", "federated_users/<cloudId>").
    actorType: actorId.split('/')[0] || '',
    actorUid: actorId.split('/').slice(1).join('/'),
    actorName: String(payload.actor?.name ?? ''),
    text,
  };
}

/**
 * rooms.json: { "<roomToken>": { "brain": "reb", "botEnv": "REB", "mode": "personal"|"team" } }
 * Returns the route or null for unrouted rooms.
 */
export function resolveRoute(rooms, roomToken) {
  if (!rooms || !roomToken) return null;
  const route = rooms[roomToken];
  if (!route || !route.brain || !route.botEnv) return null;
  return {
    brain: String(route.brain).toLowerCase(),
    botEnv: String(route.botEnv).toUpperCase(),
    mode: route.mode === 'team' ? 'team' : 'personal',
  };
}

/**
 * Command grammar (INTERAKTIONSDESIGN §2.9 / BYGGPLAN §4.3):
 * only `!queue <text>` and `!status [agent]`.
 * Returns { cmd: 'queue', text } | { cmd: 'status', agent|null } | null.
 */
export function parseCommand(text) {
  const m = String(text || '').match(/^!(queue|status)\b\s*([\s\S]*)$/i);
  if (!m) return null;
  const cmd = m[1].toLowerCase();
  const rest = m[2].trim();
  if (cmd === 'queue') return { cmd: 'queue', text: rest };
  return { cmd: 'status', agent: rest ? rest.split(/\s+/)[0].toLowerCase() : null };
}
