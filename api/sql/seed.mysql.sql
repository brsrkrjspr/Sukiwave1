START TRANSACTION;

INSERT INTO users (role, full_name, email, phone, password_hash, is_active)
VALUES (
  'buyer',
  'ShopWave Demo Buyer',
  'buyer.demo@sukiwave.app',
  '09170000002',
  '$2y$10$khkhDXXF0zSfyZ2aszuGP.2Qv475h8HDIti6REANGliiZMtg98NS.',
  1
)
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  phone = VALUES(phone),
  is_active = VALUES(is_active);

INSERT INTO users (role, full_name, email, phone, password_hash, is_active)
VALUES (
  'rider',
  'ShopWave Demo Rider',
  'rider.demo@sukiwave.app',
  '09170000003',
  '$2y$10$khkhDXXF0zSfyZ2aszuGP.2Qv475h8HDIti6REANGliiZMtg98NS.',
  1
)
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  phone = VALUES(phone),
  is_active = VALUES(is_active);

INSERT INTO rider_profiles (user_id, vehicle_type, plate_number, license_number, status, last_seen_at)
SELECT u.id, 'motorcycle', 'DEMO-1234', 'LIC-DEMO-987654', 'available', NOW()
FROM users u
WHERE u.email = 'rider.demo@sukiwave.app'
ON DUPLICATE KEY UPDATE
  vehicle_type = VALUES(vehicle_type),
  plate_number = VALUES(plate_number),
  license_number = VALUES(license_number),
  status = VALUES(status),
  last_seen_at = VALUES(last_seen_at);

INSERT INTO buyer_addresses (
  buyer_user_id, label, contact_name, contact_phone,
  address_line1, barangay, city, province, postal_code, is_default
)
SELECT
  u.id, 'Home', 'ShopWave Demo Buyer', '09170000002',
  'Lot 8 Block 3 Demo Street', 'San Isidro', 'Quezon City', 'Metro Manila', '1100', 1
FROM users u
WHERE u.email = 'buyer.demo@sukiwave.app'
  AND NOT EXISTS (
    SELECT 1
    FROM buyer_addresses ba
    WHERE ba.buyer_user_id = u.id
      AND ba.is_default = 1
  );

INSERT INTO categories (name)
VALUES ('Fresh Seafood')
ON DUPLICATE KEY UPDATE name = VALUES(name);

COMMIT;
