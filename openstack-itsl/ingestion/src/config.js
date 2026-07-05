// Konfiguration: env (.env / compose) + defaults. Hemligheter kommer ur env,
// aldrig hårdkodade. Brain-mappning speglar capture-bot/src/config.js.

const DEFAULT_BRAIN_URLS = {
  reb: 'http://brain-reb:7101',
  atlas: 'http://brain-atlas:7102',
  ada: 'http://brain-ada:7103',
  marvin: 'http://brain-marvin:7104',
  team: 'http://brain-team:7105',
};

// "30d" / "6m" / "1y" eller ISO-datum -> ISO-sträng bakåt från nu.
export function windowToIso(spec) {
  const m = /^(\d+)([dmy])$/.exec(String(spec || '').trim());
  if (m) {
    const n = Number(m[1]);
    const d = new Date();
    if (m[2] === 'd') d.setUTCDate(d.getUTCDate() - n);
    else if (m[2] === 'm') d.setUTCMonth(d.getUTCMonth() - n);
    else d.setUTCFullYear(d.getUTCFullYear() - n);
    return d.toISOString();
  }
  const d = new Date(spec);
  return Number.isNaN(d.getTime()) ? new Date(Date.now() - 365 * 864e5).toISOString() : d.toISOString();
}

function brainMap(env) {
  const out = {};
  for (const name of ['reb', 'atlas', 'ada', 'marvin', 'team']) {
    const url = env[`BRAIN_URL_${name.toUpperCase()}`] || DEFAULT_BRAIN_URLS[name];
    const key = env[`BRAIN_KEY_${name.toUpperCase()}`];
    if (key) out[name] = { url, key };
  }
  return out;
}

export function loadConfig(env = process.env) {
  const since = windowToIso(env.INGEST_SINCE || '1y');
  const experts = (env.INGEST_EXPERT_NAMES || 'johan')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
  return {
    since,
    dataDir: env.INGEST_DATA_DIR || 'data',
    secrets: {
      ZAMMAD_TOKEN: env.ZAMMAD_TOKEN || '',
    },
    sources: {
      zammad: {
        base_url: env.ZAMMAD_BASE_URL || 'https://zammad.itsl.se',
        per_page: Number(env.ZAMMAD_PER_PAGE || 100),
        include_internal_notes: env.ZAMMAD_INCLUDE_INTERNAL !== '0',
        search_query: env.ZAMMAD_QUERY || '',
        expert_names: experts,
        since,
      },
    },
    brains: brainMap(env),
  };
}
