-- Seller profile image for buyer Stores section and seller settings.
START TRANSACTION;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'seller_profiles'
    AND COLUMN_NAME = 'store_image_url'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE seller_profiles ADD COLUMN store_image_url VARCHAR(512) DEFAULT NULL AFTER shop_name',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;

