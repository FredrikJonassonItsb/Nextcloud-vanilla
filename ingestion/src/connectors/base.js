// Konnektor-bas (source-connector-contract). En subklass implementerar
// async *extract() som yieldar Evidence-objekt. Se skills/ingestion/.
//
// Evidence: { evidence_id, source, source_url, timestamp, author,
//   author_is_expert, customer?, kind, title?, text, raw_ref, components?, meta? }
export class Connector {
  static source = 'base';

  constructor({ conf, secrets, cursors, raw, log = console }) {
    this.conf = conf;
    this.secrets = secrets;
    this.cursors = cursors;
    this.raw = raw;
    this.log = log;
  }

  // eslint-disable-next-line require-yield
  async *extract() {
    throw new Error(`${this.constructor.name}.extract() not implemented`);
  }
}
