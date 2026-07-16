-- Adds session_id to pabili_pair_buyer_locations so a buyer can have separate
-- live-GPS rows per concurrent Pabili engagement with different riders, and a
-- re-engagement with the same rider (new session_id) cannot overwrite the
-- previous session's last-known GPS row mid-tear-down.
--
-- For fresh installs the table already includes session_id via
-- sukiwave_ensure_pabili_pair_buyer_locations_table() in api/config/db.php.
-- This file is for existing deployments built off schema.v15.

ALTER TABLE pabili_pair_buyer_locations
  ADD COLUMN session_id BIGINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Pabili session_id from pabili_chat_messages (per-engagement).'
    AFTER rider_user_id,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (buyer_user_id, rider_user_id, session_id);
