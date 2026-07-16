-- Seller pickup location for riders (visit store before buyer drop-off).
-- Idempotent for older MySQL versions (no IF NOT EXISTS on ADD COLUMN).
START TRANSACTION;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'seller_profiles'
    AND COLUMN_NAME = 'store_address_line1'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE seller_profiles ADD COLUMN store_address_line1 VARCHAR(255) DEFAULT NULL AFTER shop_name',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'seller_profiles'
    AND COLUMN_NAME = 'store_barangay'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE seller_profiles ADD COLUMN store_barangay VARCHAR(120) DEFAULT NULL AFTER store_address_line1',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'seller_profiles'
    AND COLUMN_NAME = 'store_city'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE seller_profiles ADD COLUMN store_city VARCHAR(120) DEFAULT NULL AFTER store_barangay',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'seller_profiles'
    AND COLUMN_NAME = 'store_latitude'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE seller_profiles ADD COLUMN store_latitude DECIMAL(10,7) DEFAULT NULL AFTER store_city',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'seller_profiles'
    AND COLUMN_NAME = 'store_longitude'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE seller_profiles ADD COLUMN store_longitude DECIMAL(10,7) DEFAULT NULL AFTER store_latitude',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;
