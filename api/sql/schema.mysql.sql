CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  role ENUM('buyer', 'seller', 'rider', 'admin') NOT NULL DEFAULT 'buyer',
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(32) DEFAULT NULL,
  profile_image_url VARCHAR(512) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seller_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  shop_name VARCHAR(120) DEFAULT NULL,
  store_description VARCHAR(255) DEFAULT NULL,
  operating_days_json TEXT DEFAULT NULL,
  store_image_url VARCHAR(512) DEFAULT NULL,
  store_address_line1 VARCHAR(255) DEFAULT NULL,
  store_barangay VARCHAR(120) DEFAULT NULL,
  store_city VARCHAR(120) DEFAULT NULL,
  store_latitude DECIMAL(10,7) DEFAULT NULL,
  store_longitude DECIMAL(10,7) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_seller_profiles_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seller_user_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED DEFAULT NULL,
  name VARCHAR(150) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  stock INT NOT NULL DEFAULT 0,
  status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  emoji VARCHAR(16) DEFAULT NULL,
  image_url VARCHAR(512) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_seller
    FOREIGN KEY (seller_user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_products_category
    FOREIGN KEY (category_id) REFERENCES categories(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_code VARCHAR(64) NOT NULL UNIQUE,
  buyer_user_id BIGINT UNSIGNED NOT NULL,
  seller_user_id BIGINT UNSIGNED NOT NULL,
  address_id BIGINT UNSIGNED DEFAULT NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
  delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_method VARCHAR(32) NOT NULL DEFAULT 'cash',
  payment_status VARCHAR(32) NOT NULL DEFAULT 'unpaid',
  status VARCHAR(32) NOT NULL DEFAULT 'New',
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_buyer
    FOREIGN KEY (buyer_user_id) REFERENCES users(id),
  CONSTRAINT fk_orders_seller
    FOREIGN KEY (seller_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  product_name_snapshot VARCHAR(150) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  quantity INT NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_items_order
    FOREIGN KEY (order_id) REFERENCES orders(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  registration_number VARCHAR(64) DEFAULT NULL,
  license_number VARCHAR(64) DEFAULT NULL,
  valid_id_number VARCHAR(64) DEFAULT NULL,
  profile_image_url VARCHAR(512) DEFAULT NULL,
  license_file_url VARCHAR(512) DEFAULT NULL,
  registration_file_url VARCHAR(512) DEFAULT NULL,
  valid_id_file_url VARCHAR(512) DEFAULT NULL,
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

CREATE TABLE IF NOT EXISTS homepage_flash_deals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL DEFAULT 'Limited deal',
  badge_text VARCHAR(40) DEFAULT NULL,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  starts_at TIMESTAMP NULL DEFAULT NULL,
  ends_at TIMESTAMP NULL DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_homepage_flash_deals_active (is_active, starts_at, ends_at),
  CONSTRAINT fk_homepage_flash_deals_product
    FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

