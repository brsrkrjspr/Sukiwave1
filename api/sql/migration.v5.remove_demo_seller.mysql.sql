-- Remove seeded demo seller (seller.demo@sukiwave.app) and their products.
-- Run once on existing databases. Safe if the user was already deleted.

START TRANSACTION;

SET @seller_id = (
  SELECT id FROM users WHERE email = 'seller.demo@sukiwave.app' LIMIT 1
);

DELETE oi FROM order_items oi
INNER JOIN orders o ON o.id = oi.order_id
WHERE @seller_id IS NOT NULL AND o.seller_user_id = @seller_id;

DELETE FROM orders
WHERE @seller_id IS NOT NULL AND seller_user_id = @seller_id;

DELETE FROM products
WHERE @seller_id IS NOT NULL AND seller_user_id = @seller_id;

DELETE FROM seller_profiles
WHERE @seller_id IS NOT NULL AND user_id = @seller_id;

DELETE FROM users
WHERE email = 'seller.demo@sukiwave.app';

COMMIT;
