/**
 * Write firewall (CONTRACTS.md section 3 + BYGGPLAN section 2.3).
 *
 * Loads the shared regex list from stack/shared/pii-patterns.json and checks
 * content BEFORE any embedding/LLM call. A match is a hard refusal: the
 * caller must return HTTP 422 with the human-readable Swedish reason and must
 * not store anything nor forward the content to OpenRouter.
 *
 * The FirewallError message never contains the matched content (no PII in
 * logs or error responses) — only the pattern id and the Swedish reason.
 */

import { readFileSync } from "node:fs";

export class FirewallError extends Error {
  constructor(patternId, reasonSv, messageSv) {
    super(`${messageSv} (${reasonSv})`);
    this.name = "FirewallError";
    this.patternId = patternId;
    this.reasonSv = reasonSv;
    this.httpStatus = 422;
  }
}

/**
 * Load and compile the shared pattern file.
 * Throws on missing/invalid file: the service must not boot without its firewall.
 */
export function loadFirewall(jsonPath) {
  const raw = JSON.parse(readFileSync(jsonPath, "utf8"));
  if (!Array.isArray(raw.patterns) || raw.patterns.length === 0) {
    throw new Error(`Invalid pii-patterns file (no patterns): ${jsonPath}`);
  }
  const messageSv =
    raw.message_sv ||
    "Blockerat: innehållet matchar mönster som inte får lagras i hjärnor (PII/secrets).";

  const patterns = raw.patterns.map((p) => {
    if (!p.id || !p.regex) throw new Error(`Invalid pattern entry in ${jsonPath}`);
    // Strip the global flag: .test() with /g/ is stateful across calls.
    const flags = (p.flags || "").replace(/g/g, "");
    return { id: p.id, reasonSv: p.reason_sv || p.id, re: new RegExp(p.regex, flags) };
  });

  const limits = raw.limits || {};
  const roleRe = limits.role_prefix_regex
    ? new RegExp(limits.role_prefix_regex, limits.role_prefix_flags || "gim")
    : null;

  /**
   * @param {string} text
   * @returns {null | { patternId: string, reasonSv: string }}
   */
  function check(text) {
    if (typeof text !== "string" || text.length === 0) return null;

    for (const p of patterns) {
      if (p.re.test(text)) return { patternId: p.id, reasonSv: p.reasonSv };
    }

    if (limits.max_chars && text.length > limits.max_chars) {
      return {
        patternId: "max_chars",
        reasonSv:
          limits.reason_max_chars_sv || `innehållet är för stort (över ${limits.max_chars} tecken)`,
      };
    }

    if (roleRe && limits.max_role_prefixed_lines) {
      const matches = text.match(roleRe);
      if (matches && matches.length > limits.max_role_prefixed_lines) {
        return {
          patternId: "role_prefixed_lines",
          reasonSv:
            limits.reason_role_lines_sv ||
            `innehållet ser ut som en transkriptdump (fler än ${limits.max_role_prefixed_lines} rollprefixade rader)`,
        };
      }
    }

    return null;
  }

  /** Throws FirewallError on a blocked match; otherwise returns undefined. */
  function assert(text) {
    const hit = check(text);
    if (hit) throw new FirewallError(hit.patternId, hit.reasonSv, messageSv);
  }

  return { check, assert, messageSv };
}
