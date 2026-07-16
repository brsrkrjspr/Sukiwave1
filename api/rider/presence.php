<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    $riderUserId = sukiwave_require_rider_from_bearer($pdo);

    if ($method === 'GET') {
        $st = $pdo->prepare(
            "SELECT rp.user_id,
                    rp.status,
                    rp.last_seen_at,
                    rp.current_latitude,
                    rp.current_longitude
             FROM rider_profiles rp
             WHERE rp.user_id = ?
             LIMIT 1"
        );
        $st->execute([$riderUserId]);
        $row = $st->fetch();
        if (!$row) {
            json_response([
                'success' => true,
                'data' => [
                    'user_id' => $riderUserId,
                    'status' => 'offline',
                    'is_online' => false,
                    'last_seen_at' => null,
                    'current_latitude' => null,
                    'current_longitude' => null,
                ],
            ]);
        }
        $status = strtolower((string)($row['status'] ?? 'offline'));
        $row['is_online'] = $status === 'available';
        json_response(['success' => true, 'data' => $row]);
    }

    $input = read_json_body();
    $isOnlineRaw = $input['is_online'] ?? false;
    $isOnline = filter_var($isOnlineRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    if ($isOnline === null) {
        $isOnline = (int)$isOnlineRaw === 1;
    }

    $lat = isset($input['latitude']) ? (float)$input['latitude'] : null;
    $lng = isset($input['longitude']) ? (float)$input['longitude'] : null;
    $nextStatus = $isOnline ? 'available' : 'offline';

    // Ensure a profile row exists for rider presence updates.
    $ensure = $pdo->prepare(
        "INSERT INTO rider_profiles (user_id, vehicle_type, status, last_seen_at, created_at, updated_at)
         VALUES (?, 'motorcycle', ?, NOW(), NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           status = VALUES(status),
           last_seen_at = NOW(),
           updated_at = NOW()"
    );
    $ensure->execute([$riderUserId, $nextStatus]);

    $up = $pdo->prepare(
        "UPDATE rider_profiles
         SET status = :status,
             last_seen_at = NOW(),
             current_latitude = :latitude,
             current_longitude = :longitude
         WHERE user_id = :user_id"
    );
    $up->execute([
        'status' => $nextStatus,
        'latitude' => $lat,
        'longitude' => $lng,
        'user_id' => $riderUserId,
    ]);

    $st = $pdo->prepare(
        "SELECT rp.user_id,
                rp.status,
                rp.last_seen_at,
                rp.current_latitude,
                rp.current_longitude
         FROM rider_profiles rp
         WHERE rp.user_id = ?
         LIMIT 1"
    );
    $st->execute([$riderUserId]);
    $row = $st->fetch() ?: [];
    $status = strtolower((string)($row['status'] ?? 'offline'));
    $row['is_online'] = $status === 'available';

    json_response([
        'success' => true,
        'message' => $isOnline ? 'Rider is online.' : 'Rider is offline.',
        'data' => $row,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to update rider presence.',
        'error' => $e->getMessage(),
    ], 400);
}

