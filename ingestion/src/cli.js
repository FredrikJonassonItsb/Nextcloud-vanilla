#!/usr/bin/env node
// CLI:
//   node src/cli.js extract --source <zammad> --brain <reb|atlas|ada|marvin|team> [--since 1y]
//   node src/cli.js extract --source <zammad> --dry-run [--since 7d] [--max 50]
//
// Orkestrerar source-connector-contract: konnektorn yieldar Evidence, varje
// post normaliseras och skrivs till målhjärnan via openbrain-svc /ingest.
// --dry-run: hämta + normalisera + bevara rådata, men skriv INTE till någon
// brain; isolerar cursors/rådata i data/_dryrun så inget riktigt tillstånd rörs.
import { join } from 'node:path';
import { loadConfig } from './config.js';
import { CursorStore } from './cursors.js';
import { RawStore } from './rawstore.js';
import { BrainClient } from './brain.js';
import { toBrainPost } from './normalize.js';
import { pseudonymize } from './pseudonymize.js';
import { ZammadConnector } from './connectors/zammad.js';

const CONNECTORS = { zammad: ZammadConnector };

function parseArgs(argv) {
  const out = { _: [] };
  for (let i = 0; i < argv.length; i += 1) {
    const a = argv[i];
    if (a.startsWith('--')) {
      const k = a.slice(2);
      const next = argv[i + 1];
      if (next && !next.startsWith('--')) {
        out[k] = next;
        i += 1;
      } else {
        out[k] = true;
      }
    } else {
      out._.push(a);
    }
  }
  return out;
}

function usage() {
  console.error(
    'Usage:\n' +
      '  node src/cli.js extract --source <zammad> --brain <reb|atlas|ada|marvin|team> [--since 1y] [--pseudonymize]\n' +
      '  node src/cli.js extract --source <zammad> --dry-run [--since 7d] [--max 50] [--pseudonymize]',
  );
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  if (args._[0] !== 'extract') {
    usage();
    process.exit(2);
  }
  const dryRun = !!args['dry-run'];
  const pseudo = !!args.pseudonymize;
  const max = args.max ? Number(args.max) : Infinity;

  const env = { ...process.env };
  if (args.since) env.INGEST_SINCE = args.since;
  // Dry-run isolerar cursors + rådata så inget riktigt tillstånd rörs.
  if (dryRun) env.INGEST_DATA_DIR = join(env.INGEST_DATA_DIR || 'data', '_dryrun');
  const cfg = loadConfig(env);

  const source = args.source;
  const Conn = CONNECTORS[source];
  if (!Conn) {
    console.error(`Okänd källa: ${source}. Tillgängliga: ${Object.keys(CONNECTORS).join(', ')}`);
    process.exit(2);
  }

  let brain = null;
  const brainName = args.brain;
  if (!dryRun) {
    const brainCfg = cfg.brains[brainName];
    if (!brainCfg) {
      console.error(`Okänd/okonfigurerad brain: ${brainName} (saknar BRAIN_KEY_${String(brainName).toUpperCase()}?)`);
      process.exit(2);
    }
    brain = new BrainClient({ url: brainCfg.url, key: brainCfg.key });
  }

  const cursors = new CursorStore(join(cfg.dataDir, 'cursors.json'));
  const raw = new RawStore(join(cfg.dataDir, 'raw'), source);
  const conn = new Conn({ conf: cfg.sources[source], secrets: cfg.secrets, cursors, raw });

  let total = 0;
  let created = 0;
  let merged = 0;
  let blocked = 0;
  let failed = 0;
  const byKind = {};
  const sample = [];

  for await (const ev of conn.extract()) {
    total += 1;
    // Pseudonymisera fritext-fält före skrivning (pii-pseudonymize-skillen).
    if (pseudo) {
      ev.text = pseudonymize(ev.text);
      if (ev.title) ev.title = pseudonymize(ev.title);
      if (ev.author) ev.author = pseudonymize(ev.author);
    }
    byKind[ev.kind] = (byKind[ev.kind] || 0) + 1;
    if (dryRun) {
      // Struktursample UTAN brödtext (undvik att dumpa kund-PII i loggen).
      if (sample.length < 5) {
        sample.push({
          id: ev.evidence_id,
          kind: ev.kind,
          expert: ev.author_is_expert,
          ts: ev.timestamp,
          chars: (ev.text || '').length,
          url: ev.source_url,
        });
      }
    } else {
      try {
        const r = await brain.ingest(toBrainPost(ev));
        if (r.blocked) blocked += 1;
        else if (r.action === 'merged') merged += 1;
        else created += 1;
      } catch (e) {
        failed += 1;
        console.error(`ingest fail ${ev.evidence_id}: ${e.message}`);
      }
    }
    if (total >= max) break;
  }

  if (dryRun) {
    console.error('DRY-RUN (ingen brain-skrivning). Struktursample (ingen brödtext):');
    for (const s of sample) console.error('  ' + JSON.stringify(s));
    console.log(
      `RUN_RESULT: source=${source} DRY-RUN since=${cfg.since.slice(0, 10)} total=${total} by_kind=${JSON.stringify(byKind)}`,
    );
  } else {
    console.log(
      `RUN_RESULT: source=${source} brain=${brainName} since=${cfg.since.slice(0, 10)} ` +
        `total=${total} created=${created} merged=${merged} blocked=${blocked} failed=${failed}`,
    );
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
