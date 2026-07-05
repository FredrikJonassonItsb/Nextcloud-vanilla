// Rådata bevaras ALLTID (source-connector-contract, invariant 2) innan
// normalisering — spårbarhet tillbaka till primärkällan (raw_ref).
import { writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';

export class RawStore {
  constructor(rawDir, source) {
    this.source = source;
    this.dir = join(rawDir, source);
    mkdirSync(this.dir, { recursive: true });
  }

  write(objId, payload) {
    const safe = String(objId).replace(/[/\\]/g, '_');
    writeFileSync(join(this.dir, `${safe}.json`), JSON.stringify(payload, null, 2));
    return `data/raw/${this.source}/${safe}.json`;
  }
}
