/**
 * Configuration loader for openbrain-svc.
 *
 * Env contract (CONTRACTS.md section 5 + 8):
 *   DATABASE_URL        (required)  postgres://u_<name>:...@brain-db:5432/brain_<name>
 *   BRAIN_KEY           (required)  Bearer token for /mcp and /ingest
 *   OPENROUTER_API_KEY  (optional)  missing => pending mode (embedding=NULL + backfill)
 *   EMBED_MODEL         (optional)  default openai/text-embedding-3-small
 *
 * Everything else has sane defaults and is overridable for ops/tests.
 */

import path from "node:path";
import { fileURLToPath } from "node:url";

const SERVICE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

export function loadConfig(env = process.env) {
  const required = (key) => {
    const v = env[key];
    if (!v || !String(v).trim()) {
      throw new Error(`Missing required environment variable: ${key}`);
    }
    return String(v).trim();
  };

  return {
    port: parseInt(env.PORT || "7100", 10),
    databaseUrl: required("DATABASE_URL"),
    brainKey: required("BRAIN_KEY"),

    openrouterApiKey: (env.OPENROUTER_API_KEY || "").trim(),
    openrouterBase: (env.OPENROUTER_BASE || "https://openrouter.ai/api/v1").replace(/\/$/, ""),
    embedModel: env.EMBED_MODEL || "openai/text-embedding-3-small",
    chatModel: env.CHAT_MODEL || "openai/gpt-4o-mini",

    citationBaseUrl: env.OPEN_BRAIN_CITATION_BASE_URL || "https://openbrain.local/thoughts",

    // Shared firewall list lives one level above the service dir (repo layout
    // openstack-itsl/{openbrain-svc,stack/shared}); the Dockerfile preserves
    // the same relative layout (/srv/openbrain-svc + /srv/stack/shared).
    piiPatternsPath:
      env.PII_PATTERNS_PATH || path.resolve(SERVICE_ROOT, "../stack/shared/pii-patterns.json"),

    // Author attribution for MCP writes on brains whose thoughts table has a
    // first-class author column (brain_team). Agent code of the configured
    // client, per BYGGPLAN section 2.1.
    defaultAuthor: (env.DEFAULT_AUTHOR || "").trim(),

    // PII/secrets write firewall. Default ON. Set PII_FIREWALL_ENABLED=0 to
    // accept everything (internal use with consent; re-enable later, no code
    // change). "0"/"false"/"off" (case-insensitive) disable it.
    firewallEnabled: !/^(0|false|off)$/i.test((env.PII_FIREWALL_ENABLED ?? "1").trim()),

    backfillIntervalMs: parseInt(env.BACKFILL_INTERVAL_MS || String(5 * 60 * 1000), 10),
    backfillBatchSize: parseInt(env.BACKFILL_BATCH_SIZE || "25", 10),
  };
}
