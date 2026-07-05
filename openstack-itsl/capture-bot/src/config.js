// Env + file configuration (CONTRACTS §8). Secrets come from /opt/openstack/.env
// via compose — never baked into the image, never logged.
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

export const BRAIN_NAMES = ['reb', 'atlas', 'ada', 'marvin', 'team'];
export const BOT_ENVS = ['REB', 'ATLAS', 'ADA', 'MARVIN'];

// Compose service names + ports per CONTRACTS §4 (host port == container port
// unless overridden with BRAIN_URL_<NAME>).
const DEFAULT_BRAIN_URLS = {
  reb: 'http://brain-reb:7101',
  atlas: 'http://brain-atlas:7102',
  ada: 'http://brain-ada:7103',
  marvin: 'http://brain-marvin:7104',
  team: 'http://brain-team:7105',
};

export function loadConfig(env = process.env) {
  const ncBase = (env.NC_BASE || 'https://dev15.hubs.se').replace(/\/+$/, '');

  const botSecrets = {};
  for (const b of BOT_ENVS) {
    const v = env[`TALK_BOT_SECRET_${b}`];
    if (v) botSecrets[b] = v;
  }

  const brainUrls = {};
  const brainKeys = {};
  for (const name of BRAIN_NAMES) {
    brainUrls[name] = env[`BRAIN_URL_${name.toUpperCase()}`] || DEFAULT_BRAIN_URLS[name];
    const key = env[`BRAIN_KEY_${name.toUpperCase()}`];
    if (key) brainKeys[name] = key;
  }

  const roomsPath = env.ROOMS_PATH || fileURLToPath(new URL('../rooms.json', import.meta.url));
  let rooms = {};
  if (existsSync(roomsPath)) {
    rooms = JSON.parse(readFileSync(roomsPath, 'utf8'));
    // Drop comment keys like "__generator_note__".
    for (const key of Object.keys(rooms)) {
      if (key.startsWith('__')) delete rooms[key];
    }
  }

  return {
    port: Number.parseInt(env.PORT || '8790', 10),
    ncBase,
    databaseUrl: env.DATABASE_URL || '',
    botSecrets,
    brainUrls,
    brainKeys,
    rooms,
    roomsPath,
    piiPatternsPath: env.PII_PATTERNS_PATH || fileURLToPath(new URL('../pii-patterns.json', import.meta.url)),
    // PII firewall. Default ON. PII_FIREWALL_ENABLED=0/false/off accepts all.
    firewallEnabled: !/^(0|false|off)$/i.test(String(env.PII_FIREWALL_ENABLED ?? '1').trim()),
    bootstrapPath: env.BOOTSTRAP_PATH || '/opt/openstack/state/bootstrap.json',
    botEngineUser: env.BOT_ENGINE_USER || 'bot-engine',
    botEnginePassword: env.BOT_APP_PASSWORD_ENGINE || '',
  };
}

/** Startup sanity: log (never throws — /healthz must come up regardless). */
export function validateConfig(config, log) {
  if (!config.databaseUrl) log.error({ msg: 'DATABASE_URL is not set — dedupe will fail' });
  if (Object.keys(config.rooms).length === 0) {
    log.error({ msg: 'rooms.json empty or missing — all webhooks will be ignored', roomsPath: config.roomsPath });
  }
  for (const [token, route] of Object.entries(config.rooms)) {
    if (!config.botSecrets[String(route.botEnv || '').toUpperCase()]) {
      log.error({ msg: 'room routed to bot without TALK_BOT_SECRET', roomToken: token, botEnv: route.botEnv });
    }
    if (!config.brainKeys[String(route.brain || '').toLowerCase()]) {
      log.error({ msg: 'room routed to brain without BRAIN_KEY', roomToken: token, brain: route.brain });
    }
  }
  if (!config.botEnginePassword) {
    log.error({ msg: 'BOT_APP_PASSWORD_ENGINE not set — !queue and !status will fail' });
  }
}
