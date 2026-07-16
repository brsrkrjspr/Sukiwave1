-- Pabili session lifecycle (Mark as Done feature).
-- Adds an authoritative status row per (buyer, rider, session_id) so completion
-- is no longer inferred from a chat marker message.

CREATE TABLE IF NOT EXISTS pabili_sessions (
    buyer_user_id INT UNSIGNED NOT NULL,
    rider_user_id INT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    pabili_status ENUM('active', 'completed') NOT NULL DEFAULT 'active',
    completed_at DATETIME NULL,
    completed_by ENUM('buyer', 'rider') NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (buyer_user_id, rider_user_id, session_id),
    KEY idx_pabili_sessions_status (pabili_status, rider_user_id),
    KEY idx_pabili_sessions_completed (completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
