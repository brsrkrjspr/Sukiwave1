<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    $sellerUserId = sukiwave_require_seller_from_bearer($pdo);

    $sql = "SELECT
            o.id AS order_id,
            o.order_code,
            o.status,
            o.total_amount,
            o.notes,
            o.created_at,
            o.updated_at,
            u.full_name AS buyer_name,
            COALESCE(
                (SELECT GROUP_CONCAT(
                    CONCAT(oi.quantity, 'x ', oi.product_name_snapshot)
                    ORDER BY oi.id SEPARATOR ', '
                 )
                 FROM order_items oi
                 WHERE oi.order_id = o.id),
                ''
            ) AS items_summary
        FROM orders o
        JOIN users u ON u.id = o.buyer_user_id
        WHERE o.seller_user_id = :sid
        ORDER BY o.updated_at DESC, o.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['sid' => $sellerUserId]);
    $rows = $stmt->fetchAll();

    $orderIds = [];
    foreach ($rows as $r) {
        $orderIds[] = (int)$r['order_id'];
    }
    $orderIds = array_values(array_unique(array_filter($orderIds)));

    $linesByOrder = [];
    if ($orderIds !== []) {
        $hasImg = sukiwave_products_has_image_url_column($pdo);
        $imgSelect = $hasImg ? 'p.image_url' : 'NULL AS image_url';
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $lineSql = "SELECT oi.order_id, oi.quantity, oi.product_name_snapshot, {$imgSelect}
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id IN ({$placeholders})
            ORDER BY oi.order_id ASC, oi.id ASC";
        $lineStmt = $pdo->prepare($lineSql);
        $lineStmt->execute($orderIds);
        while ($li = $lineStmt->fetch(PDO::FETCH_ASSOC)) {
            $oid = (int)$li['order_id'];
            if (!isset($linesByOrder[$oid])) {
                $linesByOrder[$oid] = [];
            }
            $rawUrl = $li['image_url'] ?? null;
            $url = $rawUrl !== null && $rawUrl !== '' ? (string)$rawUrl : null;
            $linesByOrder[$oid][] = [
                'quantity' => (int)$li['quantity'],
                'name' => (string)$li['product_name_snapshot'],
                'image_url' => $url,
            ];
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $oid = (int)$row['order_id'];
        $out[] = [
            'order_id' => $oid,
            'order_code' => (string)$row['order_code'],
            'buyer_name' => (string)$row['buyer_name'],
            'items_summary' => (string)$row['items_summary'],
            'line_items' => $linesByOrder[$oid] ?? [],
            'total_amount' => (float)$row['total_amount'],
            'status' => (string)$row['status'],
            'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
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
        'message' => $e->getMessage(),
    ], 400);
}
