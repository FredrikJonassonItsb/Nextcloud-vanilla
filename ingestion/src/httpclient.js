// Minimal HTTP-klient: global fetch (Node 22) + retry/backoff + JSON.
// Retry på 429/5xx och nätverksfel; 4xx (utom 429) kastas direkt (med .status).

export class HttpClient {
  constructor({ baseUrl = '', headers = {}, retries = 3, log = console } = {}) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.headers = headers;
    this.retries = retries;
    this.log = log;
  }

  _url(path, params) {
    const u = new URL(/^https?:/i.test(path) ? path : `${this.baseUrl}${path}`);
    if (params) {
      for (const [k, v] of Object.entries(params)) {
        if (v !== undefined && v !== null) u.searchParams.set(k, String(v));
      }
    }
    return u;
  }

  async getJson(path, { params, headers } = {}) {
    let attempt = 0;
    for (;;) {
      try {
        const res = await fetch(this._url(path, params), {
          headers: { ...this.headers, ...headers },
        });
        if (res.status === 429 || res.status >= 500) {
          const e = new Error(`GET ${path} -> ${res.status}`);
          e.status = res.status;
          throw e;
        }
        if (!res.ok) {
          const e = new Error(`GET ${path} -> ${res.status}`);
          e.status = res.status;
          e.noRetry = true;
          throw e;
        }
        return await res.json();
      } catch (err) {
        if (err.noRetry || ++attempt > this.retries) throw err;
        const backoff = Math.min(30000, 500 * 2 ** (attempt - 1));
        this.log.warn?.(`http retry ${attempt}/${this.retries} (${backoff}ms): ${err.message}`);
        await new Promise((r) => setTimeout(r, backoff));
      }
    }
  }
}
