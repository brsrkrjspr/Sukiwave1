<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$riderUserId = (int)($input['rider_user_id'] ?? 0);
$sessionId = (int)($input['session_id'] ?? 0);
$latRaw = $input['latitude'] ?? null;
$lngRaw = $input['longitude'] ?? null;

if ($riderUserId <= 0) {
    json_response(['success' => false, 'message' => 'rider_user_id is required.'], 422);
}
if ($sessionId <= 0) {
    json_response(['success' => false, 'message' => 'session_id is required.'], 422);
}
if ($latRaw === null || $lngRaw === null || $latRaw === '' || $lngRaw === '') {
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
    sukiwave_ensure_pabili_pair_buyer_locations_table($pdo);
    $buyerUserId = sukiwave_require_buyer_from_bearer($pdo);

    $st = $pdo->prepare(
        "SELECT id FROM users WHERE id = ? AND role = 'rider' AND is_active = 1 LIMIT 1"
    );
    $st->execute([$riderUserId]);
    if (!$st->fetch()) {
        json_response(['success' => false, 'message' => 'Rider not found.'], 404);
    }

    $up = $pdo->prepare(
        'INSERT INTO pabili_pair_buyer_locations
            (buyer_user_id, rider_user_id, session_id, latitude, longitude, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            updated_at = NOW()'
    );
    $up->execute([$buyerUserId, $riderUserId, $sessionId, $latitude, $longitude]);

    json_response([
        'success' => true,
        'message' => 'Buyer location updated.',
        'data' => [
            'buyer_user_id' => $buyerUserId,
            'rider_user_id' => $riderUserId,
            'session_id' => $sessionId,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to save buyer location.',
        'error' => $e->getMessage(),
    ], 400);
}
