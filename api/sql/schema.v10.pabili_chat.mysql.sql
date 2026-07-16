-- Pabili buyer–rider chat messages (server-backed for cross-device sync).
-- Run on MySQL 8+ / MariaDB 10.3+ alongside other schema.v* files.

CREATE TABLE IF NOT EXISTS pabili_chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stable_conversation_id INT NOT NULL COMMENT 'Matches app: (buyerId * 1000003) ^ (riderId * 9176)',
    buyer_user_id INT UNSIGNED NOT NULL,
    rider_user_id INT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'New id per Pabili engagement after mark done.',
    sender_role ENUM('buyer', 'rider') NOT NULL,
    sender_user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    image_url VARCHAR(1024) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pabili_chat_conv_id (stable_conversation_id, id),
    KEY idx_pabili_chat_conv_time (stable_conversation_id, created_at),
    KEY idx_pabili_msg_session (buyer_user_id, rider_user_id, session_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
