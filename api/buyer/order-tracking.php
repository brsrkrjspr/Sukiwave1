<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    json_response(['success' => false, 'message' => 'order_id is required.'], 422);
}

try {
    $pdo = db();
    $buyerUserId = sukiwave_require_buyer_from_bearer($pdo);

    $stmt = $pdo->prepare(
        "SELECT
            o.id AS order_id,
            o.order_code,
            o.status AS order_status,
            (
                SELECT d2.status
                FROM deliveries d2
                WHERE d2.order_id = o.id
                ORDER BY d2.updated_at DESC, d2.id DESC
                LIMIT 1
            ) AS delivery_status,
            (
                SELECT d2.rider_user_id
                FROM deliveries d2
                WHERE d2.order_id = o.id
                ORDER BY d2.updated_at DESC, d2.id DESC
                LIMIT 1
            ) AS rider_user_id,
            ba.latitude AS dropoff_latitude,
            ba.longitude AS dropoff_longitude
         FROM orders o
         LEFT JOIN buyer_addresses ba ON ba.id = o.address_id
         WHERE o.id = :order_id
           AND o.buyer_user_id = :buyer_user_id
         LIMIT 1"
    );
    $stmt->execute([
        'order_id' => $orderId,
        'buyer_user_id' => $buyerUserId,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['success' => false, 'message' => 'Order not found.'], 404);
    }

    $riderUserId = isset($row['rider_user_id']) ? (int)$row['rider_user_id'] : 0;
    $riderLat = null;
    $riderLng = null;
    $riderUpdatedAt = null;

    if ($riderUserId > 0) {
        $rp = $pdo->prepare(
            "SELECT current_latitude, current_longitude, last_seen_at
             FROM rider_profiles
             WHERE user_id = ?
             LIMIT 1"
        );
        $rp->execute([$riderUserId]);
        $loc = $rp->fetch();
        if ($loc) {
            $rawLat = $loc['current_latitude'] ?? null;
            $rawLng = $loc['current_longitude'] ?? null;
            if ($rawLat !== null && $rawLng !== null && $rawLat !== '' && $rawLng !== '') {
                $riderLat = (float)$rawLat;
                $riderLng = (float)$rawLng;
                $riderUpdatedAt = $loc['last_seen_at'] ?? null;
            }
        }
    }

    $dropLat = null;
    $dropLng = null;
    $rawDropLat = $row['dropoff_latitude'] ?? null;
    $rawDropLng = $row['dropoff_longitude'] ?? null;
    if ($rawDropLat !== null && $rawDropLng !== null && $rawDropLat !== '' && $rawDropLng !== '') {
        $dropLat = (float)$rawDropLat;
        $dropLng = (float)$rawDropLng;
    }

    json_response([
        'success' => true,
        'data' => [
            'order_id' => (int)$row['order_id'],
            'order_code' => (string)$row['order_code'],
            'order_status' => (string)$row['order_status'],
            'delivery_status' => $row['delivery_status'] !== null ? (string)$row['delivery_status'] : null,
            'rider_user_id' => $riderUserId > 0 ? $riderUserId : null,
            'dropoff_latitude' => $dropLat,
            'dropoff_longitude' => $dropLng,
            'rider_latitude' => $riderLat,
            'rider_longitude' => $riderLng,
            'rider_location_updated_at' => $riderUpdatedAt,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to load order tracking.',
        'error' => $e->getMessage(),
    ], 400);
}
