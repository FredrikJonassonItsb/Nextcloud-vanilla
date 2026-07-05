-- 20-engine-meta.sql — engine_meta database, executed AS svc_engine.
-- IDEMPOTENT: safe to re-run on every deploy.
--
-- run_log      runner cost log + run journal (CONTRACTS §7)
-- capture_seen capture-bot message-id dedupe (CONTRACTS §6)
-- kv           small operational state (cursors, pause flags, daily spend, ...)

-- NB: columns MUST match runner/bin/run-agent.sh's INSERT exactly (it is the
-- sole writer). The daily USD cap sums cost_usd. Older extra columns
-- (engine_card_id/model/tokens_*/usd_estimate/journal) are kept nullable for
-- back-compat but are no longer written.
CREATE TABLE IF NOT EXISTS run_log (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  agent_code text NOT NULL,               -- e.g. 'atlas-claude'
  started_at timestamptz NOT NULL DEFAULT now(),
  finished_at timestamptz NOT NULL DEFAULT now(),
  result text,                            -- completed|review|blocked|failed|skipped|capped
  card_id text,                           -- engine card id (e.g. AE-255)
  num_turns int,
  cost_usd numeric(10,4) NOT NULL DEFAULT 0,
  session_id text,
  is_error boolean NOT NULL DEFAULT false
);
-- Back-compat for tables created by an earlier schema (idempotent).
ALTER TABLE run_log ADD COLUMN IF NOT EXISTS card_id text;
ALTER TABLE run_log ADD COLUMN IF NOT EXISTS cost_usd numeric(10,4) NOT NULL DEFAULT 0;
ALTER TABLE run_log ADD COLUMN IF NOT EXISTS session_id text;
ALTER TABLE run_log ADD COLUMN IF NOT EXISTS is_error boolean NOT NULL DEFAULT false;

CREATE INDEX IF NOT EXISTS idx_run_log_agent_started
  ON run_log (agent_code, started_at DESC);

-- daily USD cap queries (RUNNER_DAILY_USD_CAP)
CREATE INDEX IF NOT EXISTS idx_run_log_started
  ON run_log (started_at DESC);

CREATE TABLE IF NOT EXISTS capture_seen (
  room_token text NOT NULL,
  message_id text NOT NULL,
  brain text,                             -- routed target, e.g. 'brain-reb'
  status text NOT NULL DEFAULT 'pending', -- pending|stored|blocked|command (capture-bot claim-first dedupe)
  seen_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (room_token, message_id)
);
-- Backfill for tables created before the status column existed (idempotent).
ALTER TABLE capture_seen ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'pending';

CREATE INDEX IF NOT EXISTS idx_capture_seen_seen_at
  ON capture_seen (seen_at DESC);

CREATE TABLE IF NOT EXISTS kv (
  k text PRIMARY KEY,
  v jsonb NOT NULL DEFAULT '{}'::jsonb,
  updated_at timestamptz NOT NULL DEFAULT now()
);
