-- PayMongo checkout sessions linked to orders (run after schema.mysql.sql).

CREATE TABLE IF NOT EXISTS order_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'paymongo',
  provider_checkout_session_id VARCHAR(128) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'created',
  amount_centavos INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'PHP',
  checkout_url TEXT NOT NULL,
  raw_create_json JSON DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_payments_provider_session (provider, provider_checkout_session_id),
  KEY idx_order_payments_order (order_id),
  CONSTRAINT fk_order_payments_order
    FOREIGN KEY (order_id) REFERENCES orders(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
