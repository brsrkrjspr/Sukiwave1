-- Per-engagement Pabili sessions (fresh chat after "mark done").
-- Run on DBs created from older schema.v10 (without session_id).
-- Fresh installs using the current schema.v10 already include session_id.

ALTER TABLE pabili_chat_messages
  ADD COLUMN session_id BIGINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'Increments per new Pabili engagement (buyer starts new after done).'
    AFTER rider_user_id,
  ADD KEY idx_pabili_msg_session (buyer_user_id, rider_user_id, session_id, id);
