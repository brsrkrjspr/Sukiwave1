<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$orderId = (int)($input['order_id'] ?? 0);
$latRaw = $input['latitude'] ?? null;
$lngRaw = $input['longitude'] ?? null;

if ($orderId <= 0) {
    json_response(['success' => false, 'message' => 'order_id is required.'], 422);
}
if ($latRaw === null || $lngRaw === null) {
    json_response(['success' => false, 'message' => 'latitude and longitude are required.'], 422);
}

$latitude = (float)$latRaw;
$longitude = (float)$lngRaw;
if (!is_finite($latitude) || !is_finite($longitude)) {
    json_response(['success' => false, 'message' => 'Invalid coordinates.'], 422);
}
if (abs($latitude) > 90 || abs($longitude) > 180) {
    json_response(['success' => false, 'message' => 'Coordinates out of range.'], 422);
}

try {
    $pdo = db();
    $riderUserId = sukiwave_require_rider_from_bearer($pdo);

    $stmt = $pdo->prepare(
        "SELECT id, status, rider_user_id
         FROM deliveries
         WHERE order_id = :order_id
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute(['order_id' => $orderId]);
    $delivery = $stmt->fetch();
    if (!$delivery) {
        json_response(['success' => false, 'message' => 'Delivery not found for this order.'], 404);
    }

    $assigned = isset($delivery['rider_user_id']) ? (int)$delivery['rider_user_id'] : 0;
    if ($assigned !== $riderUserId) {
        json_response(['success' => false, 'message' => 'This delivery is not assigned to you.'], 403);
    }

    $status = strtolower((string)($delivery['status'] ?? ''));
    $allowed = ['assigned', 'accepted', 'picked_up', 'in_transit'];
    if (!in_array($status, $allowed, true)) {
        json_response(['success' => false, 'message' => 'Location updates are not allowed for this delivery status.'], 409);
    }

    $up = $pdo->prepare(
        "UPDATE rider_profiles
         SET current_latitude = :lat,
             current_longitude = :lng,
             last_seen_at = NOW(),
             updated_at = NOW()
         WHERE user_id = :user_id"
    );
    $up->execute([
        'lat' => $latitude,
        'lng' => $longitude,
        'user_id' => $riderUserId,
    ]);

    json_response([
        'success' => true,
        'message' => 'Location updated.',
        'data' => [
            'order_id' => $orderId,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to update delivery location.',
        'error' => $e->getMessage(),
    ], 400);
}
