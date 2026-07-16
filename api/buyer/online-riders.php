<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    sukiwave_require_buyer_from_bearer($pdo);

    $limit = (int)($_GET['limit'] ?? 30);
    if ($limit <= 0) {
        $limit = 30;
    }
    if ($limit > 100) {
        $limit = 100;
    }

    $sql = "SELECT
                u.id AS rider_user_id,
                u.full_name AS rider_name,
                u.phone AS rider_phone,
                COALESCE(rp.profile_image_url, u.profile_image_url, '') AS rider_profile_image_url,
                rp.vehicle_type,
                rp.status,
                rp.last_seen_at,
                rp.current_latitude,
                rp.current_longitude
            FROM rider_profiles rp
            INNER JOIN users u ON u.id = rp.user_id
            WHERE u.role = 'rider'
              AND u.is_active = 1
              AND rp.status = 'available'
              AND rp.last_seen_at IS NOT NULL
              AND rp.last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
            ORDER BY rp.last_seen_at DESC
            LIMIT {$limit}";

    $rows = $pdo->query($sql)->fetchAll() ?: [];
    json_response([
        'success' => true,
        'count' => count($rows),
        'data' => $rows,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to fetch online riders.',
        'error' => $e->getMessage(),
    ], 400);
}

