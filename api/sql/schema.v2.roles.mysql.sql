START TRANSACTION;

CREATE TABLE IF NOT EXISTS buyer_addresses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  buyer_user_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(40) DEFAULT 'Home',
  contact_name VARCHAR(120) NOT NULL,
  contact_phone VARCHAR(32) NOT NULL,
  address_line1 VARCHAR(255) NOT NULL,
  address_line2 VARCHAR(255) DEFAULT NULL,
  barangay VARCHAR(120) DEFAULT NULL,
  city VARCHAR(120) NOT NULL,
  province VARCHAR(120) DEFAULT NULL,
  postal_code VARCHAR(20) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  latitude DECIMAL(10,7) DEFAULT NULL,
  longitude DECIMAL(10,7) DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_buyer_addresses_buyer (buyer_user_id),
  CONSTRAINT fk_buyer_addresses_user
    FOREIGN KEY (buyer_user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rider_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  vehicle_type ENUM('bike', 'motorcycle', 'car', 'van') NOT NULL DEFAULT 'motorcycle',
  plate_number VARCHAR(32) DEFAULT NULL,
  license_number VARCHAR(64) DEFAULT NULL,
  status ENUM('offline', 'available', 'on_delivery', 'suspended') NOT NULL DEFAULT 'offline',
  current_latitude DECIMAL(10,7) DEFAULT NULL,
  current_longitude DECIMAL(10,7) DEFAULT NULL,
  last_seen_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rider_profiles_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL UNIQUE,
  rider_user_id BIGINT UNSIGNED DEFAULT NULL,
  pickup_address_id BIGINT UNSIGNED DEFAULT NULL,
  dropoff_address_id BIGINT UNSIGNED DEFAULT NULL,
  status ENUM(
    'pending_assignment',
    'assigned',
    'accepted',
    'picked_up',
    'in_transit',
    'delivered',
    'failed',
    'cancelled'
  ) NOT NULL DEFAULT 'pending_assignment',
  assignment_note VARCHAR(255) DEFAULT NULL,
  rider_note VARCHAR(255) DEFAULT NULL,
  assigned_at TIMESTAMP NULL DEFAULT NULL,
  accepted_at TIMESTAMP NULL DEFAULT NULL,
  picked_up_at TIMESTAMP NULL DEFAULT NULL,
  delivered_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_deliveries_rider (rider_user_id),
  INDEX idx_deliveries_status (status),
  CONSTRAINT fk_deliveries_order
    FOREIGN KEY (order_id) REFERENCES orders(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_deliveries_rider_user
    FOREIGN KEY (rider_user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_deliveries_dropoff_address
    FOREIGN KEY (dropoff_address_id) REFERENCES buyer_addresses(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
