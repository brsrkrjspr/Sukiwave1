-- Rider default ("home base") location.
-- Riders can pin a default spot from their profile so buyers/sellers know
-- where they typically operate from. Distinct from `current_latitude` /
-- `current_longitude`, which track the rider's live position while online.

ALTER TABLE rider_profiles
  ADD COLUMN home_address_line1 VARCHAR(255) NULL AFTER current_longitude,
  ADD COLUMN home_barangay VARCHAR(128) NULL AFTER home_address_line1,
  ADD COLUMN home_city VARCHAR(128) NULL AFTER home_barangay,
  ADD COLUMN home_latitude DECIMAL(10,7) NULL AFTER home_city,
  ADD COLUMN home_longitude DECIMAL(10,7) NULL AFTER home_latitude;
