ALTER TABLE seller_profiles
  ADD COLUMN store_description VARCHAR(255) NULL AFTER shop_name,
  ADD COLUMN operating_days_json TEXT NULL AFTER store_description;

ALTER TABLE rider_profiles
  ADD COLUMN registration_number VARCHAR(64) NULL AFTER plate_number,
  ADD COLUMN valid_id_number VARCHAR(64) NULL AFTER license_number,
  ADD COLUMN profile_image_url VARCHAR(512) NULL AFTER valid_id_number,
  ADD COLUMN license_file_url VARCHAR(512) NULL AFTER profile_image_url,
  ADD COLUMN registration_file_url VARCHAR(512) NULL AFTER license_file_url,
  ADD COLUMN valid_id_file_url VARCHAR(512) NULL AFTER registration_file_url;

