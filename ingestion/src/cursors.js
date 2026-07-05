// Inkrementell cursor-lagring (source-connector-contract, invariant 1).
// Fil-baserad (data/cursors.json) för portabilitet — kan flyttas till
// engine_meta.kv senare utan att röra konnektorerna.
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

export class CursorStore {
  constructor(path) {
    this.path = path;
    try {
      this.data = JSON.parse(readFileSync(this.path, 'utf8'));
    } catch {
      this.data = {};
    }
  }

  get(source, scope = '') {
    return this.data[`${source}:${scope}`] ?? null;
  }

  set(source, scope, value) {
    this.data[`${source}:${scope}`] = value;
    mkdirSync(dirname(this.path), { recursive: true });
    writeFileSync(this.path, JSON.stringify(this.data, null, 2));
  }
}
