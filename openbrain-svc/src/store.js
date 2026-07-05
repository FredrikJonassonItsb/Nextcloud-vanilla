/**
 * Thought store: the single write path + read queries for the brain.
 *
 * Invariants (CONTRACTS.md sections 3 + 5):
 *  1. The write firewall runs FIRST — before any OpenRouter call — for every
 *     write (capture_thought tool and POST /ingest both go through
 *     captureThought()).
 *  2. Pending mode: when OPENROUTER_API_KEY is missing or the embedding call
 *     fails, the thought is stored with embedding = NULL and
 *     metadata.embed_pending = true; backfillOnce() (run every 5 minutes)
 *     embeds pending rows once a key is available.
 *  3. Dedupe: content fingerprint (sha256 of lowercased, whitespace-collapsed
 *     content — same normalization as the SQL upsert_thought()) with
 *     ON CONFLICT metadata merge, per OB1's production upsert semantics.
 *  4. Search degrades to ILIKE with an explicit warning flag when semantic
 *     search is unavailable.
 */

import { createHash } from "node:crypto";
import { FALLBACK_METADATA } from "./openrouter.js";

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function contentFingerprint(content) {
  const normalized = content.replace(/\s+/g, " ").trim().toLowerCase();
  return createHash("sha256").update(normalized, "utf8").digest("hex");
}

function toVectorLiteral(embedding) {
  return `[${embedding.join(",")}]`;
}

function escapeLike(term) {
  return term.replace(/[\\%_]/g, (ch) => `\\${ch}`);
}

export const FALLBACK_WARNING =
  "Semantic search unavailable (embedding API missing or failing) — plain ILIKE text fallback was used; results are keyword matches, not ranked by meaning.";

export function createStore({
  pool,
  embedder,
  firewall,
  log = console,
  authorColumn = false,
  defaultAuthor = "",
}) {
  /**
   * Embed + extract metadata, degrading to pending mode per CONTRACTS §5.
   */
  async function embedAndExtract(content) {
    if (!embedder.hasKey()) {
      return {
        embedding: null,
        extracted: { ...FALLBACK_METADATA },
        embedPending: true,
        metaPending: true,
      };
    }
    const [embRes, metaRes] = await Promise.allSettled([
      embedder.embed(content),
      embedder.extractMetadata(content),
    ]);
    let embedding = null;
    let embedPending = false;
    if (embRes.status === "fulfilled") {
      embedding = embRes.value;
    } else {
      embedPending = true;
      log.warn(`[store] embedding failed, saving as pending: ${embRes.reason?.message}`);
    }
    let extracted;
    let metaPending = false;
    if (metaRes.status === "fulfilled") {
      extracted = metaRes.value;
    } else {
      extracted = { ...FALLBACK_METADATA };
      metaPending = true;
      log.warn(`[store] metadata extraction failed, will retry in backfill: ${metaRes.reason?.message}`);
    }
    return { embedding, extracted, embedPending, metaPending };
  }

  /**
   * The single write path. Throws FirewallError (422) on blocked content —
   * BEFORE any embedding call.
   *
   * @param {object} p
   * @param {string} p.content
   * @param {string} [p.source]        metadata.source (default "mcp", OB1 behavior)
   * @param {string} [p.author]        author attribution (team brain / capture-bot)
   * @param {object} [p.extraMetadata] caller-provided metadata, merged on top of extraction
   */
  async function captureThought({ content, source, author, extraMetadata = {} }) {
    firewall.assert(content);
    const extraKeys = extraMetadata && Object.keys(extraMetadata);
    if (extraKeys && extraKeys.length > 0) {
      firewall.assert(JSON.stringify(extraMetadata));
    }

    const { embedding, extracted, embedPending, metaPending } = await embedAndExtract(content);

    const effectiveAuthor = (author || defaultAuthor || "").trim() || null;
    const metadata = {
      ...extracted,
      ...extraMetadata,
      source: source || extraMetadata.source || "mcp",
    };
    if (effectiveAuthor && !metadata.author) metadata.author = effectiveAuthor;
    if (embedPending) metadata.embed_pending = true;
    if (metaPending) metadata.meta_pending = true;

    const fingerprint = contentFingerprint(content);
    const params = [
      content,
      fingerprint,
      embedding ? toVectorLiteral(embedding) : null,
      JSON.stringify(metadata),
    ];

    let authorColsSql = "";
    let authorValsSql = "";
    if (authorColumn) {
      // brain_team: author is NOT NULL. Never lose a capture over missing
      // attribution — fall back to "unknown" and log it.
      const value = effectiveAuthor || "unknown";
      if (!effectiveAuthor) {
        log.warn("[store] author column present but no author configured — writing 'unknown'");
      }
      params.push(value);
      authorColsSql = ", author";
      authorValsSql = ", $5";
    }

    const res = await pool.query(
      `INSERT INTO thoughts (content, content_fingerprint, embedding, metadata${authorColsSql})
       VALUES ($1, $2, $3::vector, $4::jsonb${authorValsSql})
       ON CONFLICT (content_fingerprint) WHERE content_fingerprint IS NOT NULL
       DO UPDATE SET
         updated_at = now(),
         metadata = thoughts.metadata || EXCLUDED.metadata,
         embedding = COALESCE(thoughts.embedding, EXCLUDED.embedding)
       RETURNING id, (xmax = 0) AS inserted`,
      params
    );

    const row = res.rows[0];
    return {
      id: row.id,
      inserted: Boolean(row.inserted),
      embedPending,
      metaPending,
      metadata,
      fingerprint,
    };
  }

  async function vectorSearch(qEmb, threshold, limit) {
    const res = await pool.query(
      `SELECT id, content, metadata, created_at,
              1 - (embedding <=> $1::vector) AS similarity
         FROM thoughts
        WHERE embedding IS NOT NULL
          AND 1 - (embedding <=> $1::vector) >= $2
        ORDER BY embedding <=> $1::vector
        LIMIT $3`,
      [toVectorLiteral(qEmb), threshold, limit]
    );
    return { rows: res.rows, fallback: false, warning: null };
  }

  async function fallbackSearch(query, limit) {
    const terms = query.split(/\s+/).filter((t) => t.length >= 2);
    const patterns = (terms.length ? terms : [query]).map((t) => `%${escapeLike(t)}%`);
    const res = await pool.query(
      `SELECT id, content, metadata, created_at, NULL::float AS similarity
         FROM thoughts
        WHERE content ILIKE ANY($1::text[])
        ORDER BY created_at DESC
        LIMIT $2`,
      [patterns, limit]
    );
    return { rows: res.rows, fallback: true, warning: FALLBACK_WARNING };
  }

  /**
   * Semantic search with ILIKE degradation (CONTRACTS §5).
   * @returns {Promise<{rows: object[], fallback: boolean, warning: string|null}>}
   */
  async function searchThoughts({ query, limit = 10, threshold = 0.25 }) {
    if (!embedder.hasKey()) return fallbackSearch(query, limit);
    let qEmb;
    try {
      qEmb = await embedder.embed(query);
    } catch (err) {
      log.warn(`[store] query embedding failed, using ILIKE fallback: ${err.message}`);
      return fallbackSearch(query, limit);
    }
    return vectorSearch(qEmb, threshold, limit);
  }

  async function listThoughts({ limit = 10, type, topic, person, days } = {}) {
    const conditions = [];
    const params = [];
    let i = 1;
    if (type) {
      conditions.push(`metadata->>'type' = $${i++}`);
      params.push(type);
    }
    if (topic) {
      conditions.push(`metadata->'topics' ? $${i++}`);
      params.push(topic);
    }
    if (person) {
      conditions.push(`metadata->'people' ? $${i++}`);
      params.push(person);
    }
    if (days != null) {
      const d = Math.max(0, Math.floor(Number(days)));
      if (Number.isFinite(d) && d > 0) {
        conditions.push(`created_at >= NOW() - ($${i++}::int * INTERVAL '1 day')`);
        params.push(d);
      }
    }
    const where = conditions.length ? `WHERE ${conditions.join(" AND ")}` : "";
    const res = await pool.query(
      `SELECT content, metadata, created_at
         FROM thoughts
         ${where}
        ORDER BY created_at DESC
        LIMIT $${i}`,
      [...params, limit]
    );
    return res.rows;
  }

  async function stats() {
    const countRes = await pool.query("SELECT COUNT(*)::int AS count FROM thoughts");
    const dataRes = await pool.query(
      "SELECT metadata, created_at FROM thoughts ORDER BY created_at DESC"
    );
    return { count: countRes.rows[0]?.count || 0, rows: dataRes.rows };
  }

  async function fetchById(id) {
    if (typeof id !== "string" || !UUID_RE.test(id.trim())) return null;
    const res = await pool.query(
      `SELECT id, content, metadata, created_at, updated_at
         FROM thoughts
        WHERE id = $1::uuid
        LIMIT 1`,
      [id.trim()]
    );
    return res.rows[0] || null;
  }

  async function pendingCount() {
    const res = await pool.query(
      `SELECT COUNT(*)::int AS n
         FROM thoughts
        WHERE metadata->>'embed_pending' = 'true'
           OR metadata->>'meta_pending' = 'true'`
    );
    return res.rows[0]?.n ?? 0;
  }

  /**
   * Backfill worker body: embed (and re-extract metadata for) pending rows.
   * Safe to call on an interval; no-ops without an API key.
   */
  async function backfillOnce(batchSize = 25) {
    if (!embedder.hasKey()) return { processed: 0, failed: 0, skipped: true };

    const res = await pool.query(
      `SELECT id, content, metadata, (embedding IS NULL) AS needs_embedding
         FROM thoughts
        WHERE metadata->>'embed_pending' = 'true'
           OR metadata->>'meta_pending' = 'true'
        ORDER BY created_at
        LIMIT $1`,
      [batchSize]
    );

    let processed = 0;
    let failed = 0;
    for (const row of res.rows) {
      try {
        let embeddingLiteral = null;
        if (row.needs_embedding) {
          embeddingLiteral = toVectorLiteral(await embedder.embed(row.content));
        }

        let metaPatch = {};
        let metaResolved = true;
        if (row.metadata && row.metadata.meta_pending) {
          try {
            metaPatch = await embedder.extractMetadata(row.content);
          } catch (err) {
            metaResolved = false;
            log.warn(`[backfill] metadata extraction still failing for ${row.id}: ${err.message}`);
          }
        }

        const stripMeta = metaResolved ? " - 'meta_pending'" : "";
        await pool.query(
          `UPDATE thoughts
              SET embedding = COALESCE($2::vector, embedding),
                  metadata = (metadata || $3::jsonb) - 'embed_pending'${stripMeta}
            WHERE id = $1`,
          [row.id, embeddingLiteral, JSON.stringify(metaPatch)]
        );
        processed++;
      } catch (err) {
        failed++;
        log.warn(`[backfill] thought ${row.id} failed: ${err.message}`);
      }
    }
    return { processed, failed, skipped: false };
  }

  return {
    captureThought,
    searchThoughts,
    listThoughts,
    stats,
    fetchById,
    pendingCount,
    backfillOnce,
  };
}
