-- Live buyer GPS during an active Pabili chat (per buyer–rider pair).
-- Used by pabili/buyer-location.php (POST) and exposed in pabili/rider-location.php (GET).

CREATE TABLE IF NOT EXISTS pabili_pair_buyer_locations (
    buyer_user_id INT UNSIGNED NOT NULL,
    rider_user_id INT UNSIGNED NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (buyer_user_id, rider_user_id),
    KEY idx_pb_rider (rider_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
