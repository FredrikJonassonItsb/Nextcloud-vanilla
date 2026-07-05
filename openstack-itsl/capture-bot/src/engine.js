// !status: read the agent's ledger (AGENT STATUS) via agent_engine and render
// a compact Swedish one-liner. OCS base per CONTRACTS §3.
const OCS_BASE = '/ocs/v2.php/apps/agent_engine/api/v1';

/**
 * @param {object} p
 * @param {typeof fetch} p.fetchFn
 * @param {string} p.ncBase
 * @param {string} p.botUser
 * @param {string} p.botPassword
 */
export function createEngineClient({ fetchFn, ncBase, botUser, botPassword }) {
  const authHeader = `Basic ${Buffer.from(`${botUser}:${botPassword}`).toString('base64')}`;
  return {
    /** @returns {Promise<object|string|null>} ledger data, or null on 404. */
    async getLedger(agentCode) {
      const res = await fetchFn(`${ncBase}${OCS_BASE}/ledger/${encodeURIComponent(agentCode)}`, {
        method: 'GET',
        headers: {
          Authorization: authHeader,
          Accept: 'application/json',
          'OCS-APIRequest': 'true',
        },
      });
      if (res.status === 404) return null;
      if (!res.ok) throw new Error(`agent_engine ledger ${res.status}`);
      const data = await res.json();
      return data?.ocs?.data ?? data;
    },
  };
}

const FIELD_LABELS = [
  ['state', 'läge'],
  ['status', 'läge'],
  ['current_task', 'aktuellt kort'],
  ['currentTask', 'aktuellt kort'],
  ['queue', 'i kö'],
  ['queue_depth', 'i kö'],
  ['queueDepth', 'i kö'],
  ['done_today', 'klara idag'],
  ['doneToday', 'klara idag'],
  ['blocked', 'blockerade'],
  ['needs_input', 'väntar på svar'],
  ['needsInput', 'väntar på svar'],
  ['last_run', 'senaste körning'],
  ['lastRun', 'senaste körning'],
  ['last_result', 'senaste resultat'],
  ['lastResult', 'senaste resultat'],
  ['heartbeat', 'heartbeat'],
];

/** Compact Swedish rendering of whatever KARTLAGGNING §4.8-shaped fields exist. */
export function formatLedger(agentCode, data) {
  const name = agentCode.replace(/-claude$/, '');
  const display = name.charAt(0).toUpperCase() + name.slice(1);
  if (data === null || data === undefined) {
    return `${display}: ingen liggare hittad ännu.`;
  }
  if (typeof data === 'string') {
    const s = data.trim();
    return s ? `${display}: ${s.slice(0, 600)}` : `${display}: liggaren är tom.`;
  }
  const parts = [];
  const used = new Set();
  for (const [key, label] of FIELD_LABELS) {
    if (used.has(label)) continue;
    const v = data[key];
    if (v === undefined || v === null || v === '') continue;
    parts.push(`${label}: ${typeof v === 'object' ? JSON.stringify(v) : v}`);
    used.add(label);
  }
  if (parts.length === 0) {
    // Unknown shape — render the first few scalar fields verbatim.
    for (const [k, v] of Object.entries(data).slice(0, 6)) {
      if (v === null || typeof v === 'object') continue;
      parts.push(`${k}: ${v}`);
    }
  }
  if (parts.length === 0) return `${display}: liggaren är tom.`;
  return `${display} — ${parts.join(' · ')}`;
}
