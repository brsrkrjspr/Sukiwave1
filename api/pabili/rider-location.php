<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    $buyerUserId = (int)($_GET['buyer_user_id'] ?? 0);
    $riderUserId = (int)($_GET['rider_user_id'] ?? 0);
    $sessionId = (int)($_GET['session_id'] ?? 0);
    sukiwave_require_pabili_pair_auth($pdo, $buyerUserId, $riderUserId);

    $buyerLocation = null;
    $buyerLocationUpdatedAt = null;
    try {
        sukiwave_ensure_pabili_pair_buyer_locations_table($pdo);
        // Scope strictly to this session when session_id is supplied so a
        // stale row from a previous engagement can't bleed into the current
        // map. Older clients that don't yet pass session_id fall back to the
        // most recent row for the pair (legacy behaviour).
        if ($sessionId > 0) {
            $stb = $pdo->prepare(
                'SELECT latitude, longitude, updated_at
                 FROM pabili_pair_buyer_locations
                 WHERE buyer_user_id = ?
                   AND rider_user_id = ?
                   AND session_id = ?
                 LIMIT 1'
            );
            $stb->execute([$buyerUserId, $riderUserId, $sessionId]);
        } else {
            $stb = $pdo->prepare(
                'SELECT latitude, longitude, updated_at
                 FROM pabili_pair_buyer_locations
                 WHERE buyer_user_id = ? AND rider_user_id = ?
                 ORDER BY updated_at DESC
                 LIMIT 1'
            );
            $stb->execute([$buyerUserId, $riderUserId]);
        }
        $br = $stb->fetch();
        if ($br) {
            $bla = $br['latitude'] ?? null;
            $blo = $br['longitude'] ?? null;
            if ($bla !== null && $blo !== null && $bla !== '' && $blo !== '') {
                $buyerLocation = [
                    'latitude' => (float)$bla,
                    'longitude' => (float)$blo,
                ];
                $buyerLocationUpdatedAt = $br['updated_at'] ?? null;
            }
        }
    } catch (Throwable $e) {
        // Rider GPS still works if buyer table cannot be created or read.
    }

    $st = $pdo->prepare(
        "SELECT
            rp.user_id AS rider_user_id,
            rp.status,
            rp.last_seen_at,
            rp.current_latitude,
            rp.current_longitude
         FROM rider_profiles rp
         INNER JOIN users u ON u.id = rp.user_id
         WHERE rp.user_id = ?
           AND u.role = 'rider'
           AND u.is_active = 1
         LIMIT 1"
    );
    $st->execute([$riderUserId]);
    $row = $st->fetch();

    if (!$row) {
        json_response([
            'success' => true,
            'data' => [
                'buyer_user_id' => $buyerUserId,
                'rider_user_id' => $riderUserId,
                'session_id' => $sessionId,
                'status' => 'offline',
                'location' => null,
                'location_updated_at' => null,
                'buyer_location' => $buyerLocation,
                'buyer_location_updated_at' => $buyerLocationUpdatedAt,
            ],
        ]);
    }

    $rawLat = $row['current_latitude'] ?? null;
    $rawLng = $row['current_longitude'] ?? null;
    $lat = null;
    $lng = null;
    if ($rawLat !== null && $rawLng !== null && $rawLat !== '' && $rawLng !== '') {
        $lat = (float)$rawLat;
        $lng = (float)$rawLng;
    }

    json_response([
        'success' => true,
        'data' => [
            'buyer_user_id' => $buyerUserId,
            'rider_user_id' => (int)$row['rider_user_id'],
            'session_id' => $sessionId,
            'status' => (string)($row['status'] ?? 'offline'),
            'location' => ($lat !== null && $lng !== null)
                ? ['latitude' => $lat, 'longitude' => $lng]
                : null,
            'location_updated_at' => $row['last_seen_at'] ?? null,
            'buyer_location' => $buyerLocation,
            'buyer_location_updated_at' => $buyerLocationUpdatedAt,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to load rider location.',
        'error' => $e->getMessage(),
    ], 400);
}
