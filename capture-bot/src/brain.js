// openbrain-svc ingest client (CONTRACTS §5):
// POST {BRAIN_URL}/ingest  { content, source, author?, metadata? }
// Authorization: Bearer BRAIN_KEY_<NAME>. 422 = write firewall block.

/**
 * @param {object} p
 * @param {typeof fetch} p.fetchFn
 * @param {Record<string,string>} p.brainUrls keyed by brain short name (reb..team)
 * @param {Record<string,string>} p.brainKeys keyed by brain short name
 */
export function createBrainClient({ fetchFn, brainUrls, brainKeys }) {
  return {
    /**
     * @returns {Promise<{ok:true} | {blocked:true, reason:string}>}
     * Throws on transport errors and non-2xx/non-422 statuses (caller returns 500
     * so Talk retries — a capture is never silently dropped).
     */
    async ingest(brain, { content, source, author, metadata }) {
      const url = brainUrls[brain];
      const key = brainKeys[brain];
      if (!url || !key) throw new Error(`brain ${brain} not configured (url/key missing)`);
      const body = { content, source };
      if (author) body.author = author;
      if (metadata) body.metadata = metadata;
      const res = await fetchFn(`${url.replace(/\/+$/, '')}/ingest`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${key}`,
        },
        body: JSON.stringify(body),
      });
      if (res.status === 422) {
        let reason = 'innehållet matchar mönster som inte får lagras i hjärnor (PII/secrets)';
        try {
          const data = await res.json();
          if (data && (data.reason || data.message || data.error)) {
            reason = String(data.reason || data.message || data.error);
          }
        } catch {
          /* keep default reason */
        }
        return { blocked: true, reason };
      }
      if (!res.ok) {
        throw new Error(`brain ${brain} /ingest responded ${res.status}`);
      }
      return { ok: true };
    },
  };
}
