// Zammad-konnektor (support) — se skills/ingestion/ingest-zammad/SKILL.md.
// Högst signal: ärenden är redan incident->lösning-par. Auth: HTTP Token.
// Inkrementell via updated_at-cursor. Bevarar rådata, normaliserar tråden.
import { Connector } from './base.js';
import { HttpClient } from '../httpclient.js';
import { htmlToText, parseTs, maxIso, isoGte } from '../normalize.js';

export class ZammadConnector extends Connector {
  static source = 'zammad';

  _http() {
    const token = this.secrets.ZAMMAD_TOKEN;
    if (!token) throw new Error('ZAMMAD_TOKEN saknas i miljön.');
    return new HttpClient({
      baseUrl: this.conf.base_url,
      headers: { Authorization: `Token token=${token}`, 'Content-Type': 'application/json' },
      log: this.log,
    });
  }

  async *extract() {
    const http = this._http();
    const base = this.conf.base_url.replace(/\/+$/, '');
    const perPage = this.conf.per_page ?? 100;
    const includeInternal = this.conf.include_internal_notes !== false;
    const cursor = this.cursors.get('zammad');
    const since = maxIso(this.conf.since, cursor) || this.conf.since;
    // FÄLLA: datumform (YYYY-MM-DD), INTE full ISO — full tidsstämpel med ':' och
    // '+00:00' ger Elasticsearch tyst noll träffar.
    let query = `updated_at:>=${since.slice(0, 10)}`;
    if (this.conf.search_query) query = `(${this.conf.search_query}) AND ${query}`;

    let maxUpdated = cursor || since;
    for await (const ticketId of this._iterTicketIds(http, query, perPage)) {
      const ticket = await this._fetchTicket(http, ticketId);
      if (!ticket) continue;
      const rawRef = this.raw.write(`ticket-${ticketId}`, ticket);
      const articles = await this._fetchArticles(http, ticketId);
      this.raw.write(`ticket-${ticketId}-articles`, articles);
      const tags = await this._fetchTags(http, ticketId);
      maxUpdated = maxIso(maxUpdated, ticket.updated_at) || maxUpdated;
      yield* this._normalize(base, ticket, articles, tags, includeInternal, rawRef);
    }
    this.cursors.set('zammad', '', maxUpdated);
  }

  async *_iterTicketIds(http, query, perPage) {
    let page = 1;
    let yielded = false;
    for (;;) {
      let data;
      try {
        data = await http.getJson('/api/v1/tickets/search', {
          params: { query, sort_by: 'updated_at', order_by: 'asc', per_page: perPage, page },
        });
      } catch (err) {
        // Sök-API (Elasticsearch) saknas redan på FÖRSTA sidan -> fallback listning.
        // Inte vid djup-pagineringsfel mitt i (skulle dubblera lämnade id:n).
        if ([400, 422, 501].includes(err.status) && !yielded) {
          this.log.warn?.('Zammad sök-API ej tillgängligt — fallback till listning.');
          yield* this._listTicketIds(http, perPage);
          return;
        }
        throw err;
      }
      const ids = idsFromSearch(data);
      if (!ids.length) return;
      for (const id of ids) {
        yielded = true;
        yield id;
      }
      if (ids.length < perPage) return;
      page += 1;
    }
  }

  async *_listTicketIds(http, perPage) {
    let page = 1;
    for (;;) {
      const data = await http.getJson('/api/v1/tickets', { params: { per_page: perPage, page } });
      if (!Array.isArray(data) || !data.length) return;
      for (const t of data) {
        if (isoGte(t.updated_at, this.conf.since)) yield t.id;
      }
      if (data.length < perPage) return;
      page += 1;
    }
  }

  async _fetchTicket(http, id) {
    try {
      return await http.getJson(`/api/v1/tickets/${id}`, { params: { expand: 'true' } });
    } catch (e) {
      this.log.warn?.(`Kunde inte hämta ticket ${id}: ${e.message}`);
      return null;
    }
  }

  async _fetchArticles(http, id) {
    try {
      return await http.getJson(`/api/v1/ticket_articles/by_ticket/${id}`);
    } catch {
      return [];
    }
  }

  async _fetchTags(http, id) {
    // Taggar ligger INTE på ticket-objektet ens med expand=true.
    try {
      const d = await http.getJson('/api/v1/tags', { params: { object: 'Ticket', o_id: id } });
      return Array.isArray(d?.tags) ? d.tags : [];
    } catch {
      return [];
    }
  }

  *_normalize(base, ticket, articles, tags, includeInternal, rawRef) {
    const ticketId = ticket.id;
    const url = `${base}/#ticket/zoom/${ticketId}`;
    const customer = typeof ticket.organization === 'string' ? ticket.organization : null;
    const title = ticket.title ?? null;
    const experts = (this.conf.expert_names ?? ['johan']).map((s) => String(s).toLowerCase());

    for (const [idx, art] of articles.entries()) {
      if (art.internal && !includeInternal) continue;
      // text/plain HTML-rensas INTE (skulle äta '<' i loggar/kod).
      const body =
        (art.content_type || '').toLowerCase() === 'text/plain'
          ? (art.body || '').trim()
          : htmlToText(art.body);
      if (!body) continue;

      const sender = (art.sender || '').toLowerCase();
      const author = art.from || art.created_by || '';
      const authorLc = String(author).toLowerCase();
      const kind = sender === 'customer' ? 'problem' : sender === 'agent' ? 'resolution' : 'discussion';

      yield {
        evidence_id: `zammad-ticket-${ticketId}-art-${art.id ?? idx}`,
        source: 'zammad',
        source_url: url,
        timestamp: parseTs(art.created_at),
        author: String(author) || null,
        author_is_expert: experts.some((e) => authorLc.includes(e)),
        customer,
        kind,
        title,
        text: body,
        raw_ref: rawRef,
        meta: {
          ticket_id: ticketId,
          article_id: art.id,
          state: ticket.state,
          priority: ticket.priority,
          group: ticket.group,
          internal: !!art.internal,
          sender: art.sender,
          tags: tags || [],
        },
      };
    }
  }
}

export function idsFromSearch(data) {
  if (Array.isArray(data)) {
    return data.map((t) => (t && typeof t === 'object' ? t.id : t));
  }
  if (data && Array.isArray(data.tickets)) {
    const t = data.tickets;
    return t.length && typeof t[0] === 'object' ? t.map((x) => x.id) : t;
  }
  return [];
}
