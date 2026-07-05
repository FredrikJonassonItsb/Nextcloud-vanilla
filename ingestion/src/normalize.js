// Normalisering (source-connector-contract): HTML->text, timestamp, datumhjälpare,
// och Evidence -> brain-post-mappningen. Rena funktioner, testbara utan nätverk.

export function htmlToText(html) {
  if (!html) return '';
  return String(html)
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<br\s*\/?>(?=)/gi, '\n')
    .replace(/<\/(p|div|li|tr|h[1-6])>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

// Normalisera timestamp -> ISO-sträng (tz-robust). Tal < 1e12 tolkas som epoch-sekunder.
export function parseTs(value) {
  if (value === null || value === undefined) return null;
  if (typeof value === 'number') {
    return new Date(value < 1e12 ? value * 1000 : value).toISOString();
  }
  const d = new Date(String(value).replace('Z', '+00:00'));
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

// Datumjämförelse som Date (inte lexikografiskt) -> robust mot Z vs +00:00.
export function maxIso(a, b) {
  if (!a) return b ?? null;
  if (!b) return a ?? null;
  return new Date(a).getTime() >= new Date(b).getTime() ? a : b;
}

export function isoGte(a, b) {
  if (!a) return false;
  if (!b) return true;
  return new Date(a).getTime() >= new Date(b).getTime();
}

// Evidence -> openbrain-svc /ingest-body {content, source, author?, metadata}.
export function toBrainPost(ev) {
  return {
    content: ev.text,
    source: ev.source,
    author: ev.author || undefined,
    metadata: {
      title: ev.title || null,
      source_url: ev.source_url,
      timestamp: ev.timestamp || null,
      author: ev.author || null,
      author_is_expert: !!ev.author_is_expert,
      kind: ev.kind || 'discussion',
      components: ev.components || [],
      customer: ev.customer || null,
      raw_ref: ev.raw_ref || null,
      dedupe_key: ev.evidence_id,
      ...(ev.meta || {}),
    },
  };
}
