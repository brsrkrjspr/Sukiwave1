START TRANSACTION;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'products'
    AND COLUMN_NAME = 'image_url'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE products ADD COLUMN image_url VARCHAR(512) DEFAULT NULL AFTER emoji',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
