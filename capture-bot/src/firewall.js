// PII/secret write firewall (CONTRACTS §3): shared regex list from
// stack/shared/pii-patterns.json. Runs BEFORE anything leaves the house
// (before brain /ingest, which runs the same list again server-side).
import { readFileSync } from 'node:fs';

/**
 * Accepts either { patterns: [...] } or a bare array. Each entry:
 * { id|name: string, pattern|regex: string, flags?: string, message?: string }
 */
export function compilePatterns(json) {
  const list = Array.isArray(json) ? json : json?.patterns;
  if (!Array.isArray(list)) {
    throw new Error('pii-patterns: expected an array or { patterns: [...] }');
  }
  return list.map((entry) => {
    const id = entry.id || entry.name || 'unnamed';
    const source = entry.pattern || entry.regex;
    if (!source) throw new Error(`pii-patterns: entry ${id} has no pattern`);
    return {
      id,
      re: new RegExp(source, entry.flags || ''),
      message: entry.message || id,
    };
  });
}

export function loadPatterns(path) {
  return compilePatterns(JSON.parse(readFileSync(path, 'utf8')));
}

/** @returns {{ check(text: string): null | { id: string, message: string } }} */
export function createFirewall(patterns) {
  return {
    check(text) {
      const s = String(text ?? '');
      for (const p of patterns) {
        // Fresh lastIndex per call in case a pattern carries the g flag.
        p.re.lastIndex = 0;
        if (p.re.test(s)) return { id: p.id, message: p.message };
      }
      return null;
    },
  };
}
