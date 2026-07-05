/**
 * Postgres connection + idempotent schema bootstrap.
 *
 * Schema = OB1 production schema verbatim (BYGGPLAN section 2.2 / ob1-core-docs
 * digest): UUID ids, updated_at + trigger, content_fingerprint dedupe,
 * match_thoughts() and upsert_thought(). Everything is IF NOT EXISTS /
 * CREATE OR REPLACE so it coexists with brain-db's init SQL and reruns safely
 * on every boot (deploy.sh "migrations" step is satisfied by booting).
 *
 * brain_team additionally has an author column (created by brain-db init);
 * this service detects it at boot and includes it in its write path.
 */

import pg from "pg";

export function createPool(databaseUrl) {
  return new pg.Pool({
    connectionString: databaseUrl,
    max: 10,
    idleTimeoutMillis: 30000,
    connectionTimeoutMillis: 10000,
  });
}

const SCHEMA_STATEMENTS = [
  `CREATE TABLE IF NOT EXISTS thoughts (
     id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
     content             TEXT NOT NULL,
     embedding           vector(1536),
     metadata            JSONB NOT NULL DEFAULT '{}'::jsonb,
     content_fingerprint TEXT,
     created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
     updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
   )`,
  `CREATE INDEX IF NOT EXISTS idx_thoughts_embedding_hnsw
     ON thoughts USING hnsw (embedding vector_cosine_ops)`,
  `CREATE INDEX IF NOT EXISTS idx_thoughts_metadata_gin
     ON thoughts USING gin (metadata)`,
  `CREATE INDEX IF NOT EXISTS idx_thoughts_created_at
     ON thoughts (created_at DESC)`,
  `CREATE UNIQUE INDEX IF NOT EXISTS idx_thoughts_fingerprint
     ON thoughts (content_fingerprint)
     WHERE content_fingerprint IS NOT NULL`,
  `CREATE OR REPLACE FUNCTION update_updated_at()
   RETURNS trigger AS $$
   BEGIN
     NEW.updated_at = now();
     RETURN NEW;
   END;
   $$ LANGUAGE plpgsql`,
  `DROP TRIGGER IF EXISTS thoughts_updated_at ON thoughts`,
  `CREATE TRIGGER thoughts_updated_at
     BEFORE UPDATE ON thoughts
     FOR EACH ROW
     EXECUTE FUNCTION update_updated_at()`,
  // match_thoughts — OB1 production version (threshold 0.7 default, JSONB containment filter).
  `CREATE OR REPLACE FUNCTION match_thoughts(
     query_embedding vector(1536),
     match_threshold float DEFAULT 0.7,
     match_count int DEFAULT 10,
     filter jsonb DEFAULT '{}'::jsonb
   )
   RETURNS TABLE (
     id uuid,
     content text,
     metadata jsonb,
     similarity float,
     created_at timestamptz
   )
   LANGUAGE plpgsql
   AS $$
   BEGIN
     RETURN QUERY
     SELECT
       t.id,
       t.content,
       t.metadata,
       1 - (t.embedding <=> query_embedding) AS similarity,
       t.created_at
     FROM thoughts t
     WHERE 1 - (t.embedding <=> query_embedding) > match_threshold
       AND (filter = '{}'::jsonb OR t.metadata @> filter)
     ORDER BY t.embedding <=> query_embedding
     LIMIT match_count;
   END;
   $$`,
  // upsert_thought — OB1 production version (fingerprint dedupe, metadata merge).
  `CREATE OR REPLACE FUNCTION upsert_thought(p_content TEXT, p_payload JSONB DEFAULT '{}')
   RETURNS JSONB AS $$
   DECLARE
     v_fingerprint TEXT;
     v_result JSONB;
     v_id UUID;
   BEGIN
     v_fingerprint := encode(sha256(convert_to(
       lower(trim(regexp_replace(p_content, '\\s+', ' ', 'g'))),
       'UTF8'
     )), 'hex');

     INSERT INTO thoughts (content, content_fingerprint, metadata)
     VALUES (p_content, v_fingerprint, COALESCE(p_payload->'metadata', '{}'::jsonb))
     ON CONFLICT (content_fingerprint) WHERE content_fingerprint IS NOT NULL DO UPDATE
     SET updated_at = now(),
         metadata = thoughts.metadata || COALESCE(EXCLUDED.metadata, '{}'::jsonb)
     RETURNING id INTO v_id;

     v_result := jsonb_build_object('id', v_id, 'fingerprint', v_fingerprint);
     RETURN v_result;
   END;
   $$ LANGUAGE plpgsql`,
];

export async function ensureSchema(pool, log = console) {
  // CREATE EXTENSION may require superuser; brain-db init normally does it.
  try {
    await pool.query("CREATE EXTENSION IF NOT EXISTS vector");
  } catch (err) {
    const check = await pool.query("SELECT 1 FROM pg_extension WHERE extname = 'vector'");
    if (check.rowCount === 0) {
      throw new Error(
        `pgvector extension is missing and could not be created (${err.message}). ` +
          `Create it via brain-db init SQL as superuser.`
      );
    }
    log.warn("[schema] CREATE EXTENSION skipped (already present, created by init SQL)");
  }
  for (const stmt of SCHEMA_STATEMENTS) {
    await pool.query(stmt);
  }
}

/** brain_team has a first-class author column; personal brains do not. */
export async function detectAuthorColumn(pool) {
  const r = await pool.query(
    `SELECT 1
       FROM information_schema.columns
      WHERE table_schema = current_schema()
        AND table_name = 'thoughts'
        AND column_name = 'author'`
  );
  return r.rowCount > 0;
}
