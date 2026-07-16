<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    $buyerUserId = sukiwave_require_buyer_from_bearer($pdo);

    $sql = "SELECT
                o.id AS order_id,
                o.order_code,
                o.status,
                o.total_amount,
                o.created_at,
                o.updated_at,
                COALESCE(sp.shop_name, su.full_name, 'Seller') AS seller_name,
                COALESCE(
                    (
                      SELECT GROUP_CONCAT(
                        CONCAT(oi.quantity, 'x ', oi.product_name_snapshot)
                        ORDER BY oi.id SEPARATOR ', '
                      )
                      FROM order_items oi
                      WHERE oi.order_id = o.id
                    ),
                    ''
                ) AS items_summary,
                (
                  SELECT d.status
                  FROM deliveries d
                  WHERE d.order_id = o.id
                  ORDER BY d.updated_at DESC, d.id DESC
                  LIMIT 1
                ) AS delivery_status
            FROM orders o
            LEFT JOIN users su ON su.id = o.seller_user_id
            LEFT JOIN seller_profiles sp ON sp.user_id = o.seller_user_id
            WHERE o.buyer_user_id = :buyer_user_id
            ORDER BY o.updated_at DESC, o.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['buyer_user_id' => $buyerUserId]);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'order_id' => (int)$row['order_id'],
            'order_code' => (string)$row['order_code'],
            'status' => (string)$row['status'],
            'total_amount' => (float)$row['total_amount'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'seller_name' => (string)$row['seller_name'],
            'items_summary' => (string)$row['items_summary'],
            'delivery_status' => $row['delivery_status'] !== null ? (string)$row['delivery_status'] : null,
        ];
    }

    json_response([
        'success' => true,
        'count' => count($out),
        'data' => $out,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to fetch buyer orders.',
        'error' => $e->getMessage(),
    ], 400);
}

