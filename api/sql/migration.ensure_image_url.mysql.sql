-- Idempotent: add products.image_url if missing (safe to run multiple times).
--
-- Railway MySQL: use the credentials / connection string from your Railway
-- service (Variables: MYSQL_URL, DATABASE_URL, or the plugin’s connect UI).
-- Examples:
--   • Railway dashboard → MySQL → “Query” / “Data” tab: paste this whole file and run.
--   • From your machine: mysql -h $MYSQLHOST -P $MYSQLPORT -u $MYSQLUSER -p
--     (then USE your_database; and paste, or: mysql ... < migration.ensure_image_url.mysql.sql)
--
-- The active database must be your app DB (often the name in MYSQL_DATABASE).

SET @dbname = DATABASE();
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'image_url'
  ) > 0,
  'SELECT 1 AS image_url_column_already_present',
  'ALTER TABLE products ADD COLUMN image_url VARCHAR(512) DEFAULT NULL AFTER emoji'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
