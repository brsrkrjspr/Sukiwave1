<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    $riderUserId = sukiwave_require_rider_from_bearer($pdo);
    $hasStoreLoc = sukiwave_seller_profiles_has_store_location_columns($pdo);

    $sellerCols = $hasStoreLoc
        ? 'sp.shop_name AS seller_shop_name,
            sp.store_address_line1 AS store_address_line1,
            sp.store_barangay AS store_barangay,
            sp.store_city AS store_city,
            sp.store_latitude AS pickup_latitude,
            sp.store_longitude AS pickup_longitude,'
        : 'NULL AS seller_shop_name,
            NULL AS store_address_line1,
            NULL AS store_barangay,
            NULL AS store_city,
            NULL AS pickup_latitude,
            NULL AS pickup_longitude,';

    $sql = "SELECT
            o.id AS order_id,
            o.order_code,
            o.buyer_user_id,
            o.seller_user_id,
            o.total_amount,
            o.payment_method,
            o.payment_status,
            o.status AS order_status,
            COALESCE(d.status, 'new') AS delivery_status,
            o.created_at,
            ba.id AS address_id,
            ba.label AS address_label,
            ba.contact_name,
            ba.contact_phone,
            ba.address_line1,
            ba.address_line2,
            ba.barangay,
            ba.city,
            ba.province,
            ba.postal_code,
            ba.latitude AS dropoff_latitude,
            ba.longitude AS dropoff_longitude,
            {$sellerCols}
            u_seller.full_name AS seller_contact_name
         FROM orders o
         LEFT JOIN deliveries d ON d.order_id = o.id
         LEFT JOIN buyer_addresses ba ON ba.id = o.address_id
         LEFT JOIN users u_seller ON u_seller.id = o.seller_user_id
         LEFT JOIN seller_profiles sp ON sp.user_id = o.seller_user_id
         WHERE (d.id IS NULL OR d.status = 'pending_assignment'
                OR (d.rider_user_id = :rider_user_id AND d.status IN ('accepted', 'picked_up', 'in_transit')))
           AND o.status IN ('New', 'Ready for Pickup', 'Preparing', 'Assigned to Rider', 'Out for Delivery')
         ORDER BY o.created_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['rider_user_id' => $riderUserId]);

    $rows = $stmt->fetchAll();
    json_response([
        'success' => true,
        'count' => count($rows),
        'data' => $rows,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to fetch available deliveries.',
        'error' => $e->getMessage(),
    ], 500);
}
