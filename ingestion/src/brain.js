// openbrain-svc ingest-klient (speglar capture-bot/src/brain.js, CONTRACTS §5):
//   POST {BRAIN_URL}/ingest  { content, source, author?, metadata? }
//   Authorization: Bearer BRAIN_KEY.
// 422 = write firewall block (pii-patterns.json) — vägran är FINAL, aldrig retry.

export class BrainClient {
  constructor({ url, key, log = console }) {
    this.url = url.replace(/\/+$/, '');
    this.key = key;
    this.log = log;
  }

  async ingest({ content, source, author, metadata }) {
    const res = await fetch(`${this.url}/ingest`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${this.key}`,
      },
      body: JSON.stringify({ content, source, author, metadata }),
    });
    if (res.status === 422) {
      return { status: 422, blocked: true }; // PII-brandvägg: refuse is final
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(`brain /ingest -> ${res.status}: ${JSON.stringify(data).slice(0, 200)}`);
    }
    return { status: res.status, ...data }; // data.action: created | merged
  }
}
