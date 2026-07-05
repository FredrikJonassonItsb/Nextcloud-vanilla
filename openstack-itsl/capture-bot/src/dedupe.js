// Dedupe via engine_meta.capture_seen (Talk retries webhooks on non-2xx).
// Claim-first pattern: an atomic INSERT ... ON CONFLICT DO NOTHING claims the
// message; on downstream failure the claim is released so the Talk retry can
// re-capture (capture must never be lost).
// No `pg` import here — a query function is injected (unit-testable without a DB).

const SCHEMA_SQL = `
CREATE TABLE IF NOT EXISTS capture_seen (
  room_token  text        NOT NULL,
  message_id  text        NOT NULL,
  status      text        NOT NULL DEFAULT 'pending',
  created_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (room_token, message_id)
)`;

/**
 * @param {(sql: string, params?: unknown[]) => Promise<{rowCount: number}>} query
 */
export function createDedupe(query) {
  return {
    async ensureSchema() {
      await query(SCHEMA_SQL);
    },

    /** @returns {Promise<boolean>} true when this call claimed the message (first sighting). */
    async claim(roomToken, messageId) {
      const res = await query(
        `INSERT INTO capture_seen (room_token, message_id, status)
         VALUES ($1, $2, 'pending')
         ON CONFLICT (room_token, message_id) DO NOTHING`,
        [roomToken, messageId],
      );
      return res.rowCount === 1;
    },

    /** Release a claim after a transient failure so the webhook retry can process it. */
    async release(roomToken, messageId) {
      await query(
        `DELETE FROM capture_seen
         WHERE room_token = $1 AND message_id = $2 AND status = 'pending'`,
        [roomToken, messageId],
      );
    },

    /** Finalize the claim: 'stored' | 'blocked' | 'command'. */
    async markStatus(roomToken, messageId, status) {
      await query(
        `UPDATE capture_seen SET status = $3
         WHERE room_token = $1 AND message_id = $2`,
        [roomToken, messageId, status],
      );
    },
  };
}
