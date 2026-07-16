<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

$pdo = db();

if (!sukiwave_ensure_rider_default_location_columns($pdo)) {
    json_response([
        'success' => false,
        'message' => 'Could not prepare rider default location storage. Ensure the database user can ALTER TABLE rider_profiles, or run api/sql/schema.v14.rider_default_location.mysql.sql manually.',
    ], 503);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $riderUserId = sukiwave_require_rider_from_bearer($pdo);

    if ($method === 'GET') {
        $st = $pdo->prepare(
            'SELECT user_id,
                    home_address_line1,
                    home_barangay,
                    home_city,
                    home_latitude,
                    home_longitude
             FROM rider_profiles
             WHERE user_id = ?
             LIMIT 1'
        );
        $st->execute([$riderUserId]);
        $row = $st->fetch();
        if (!$row) {
            json_response([
                'success' => true,
                'data' => [
                    'user_id' => $riderUserId,
                    'home_address_line1' => null,
                    'home_barangay' => null,
                    'home_city' => null,
                    'home_latitude' => null,
                    'home_longitude' => null,
                ],
            ]);
        }
        json_response(['success' => true, 'data' => $row]);
    }

    $input = read_json_body();
    $line1 = trim((string)($input['home_address_line1'] ?? $input['address_line1'] ?? ''));
    $barangay = trim((string)($input['home_barangay'] ?? $input['barangay'] ?? ''));
    $city = trim((string)($input['home_city'] ?? $input['city'] ?? ''));
    $latRaw = $input['home_latitude'] ?? $input['latitude'] ?? null;
    $lngRaw = $input['home_longitude'] ?? $input['longitude'] ?? null;

    if ($latRaw === null || $lngRaw === null || $latRaw === '' || $lngRaw === '') {
        json_response([
            'success' => false,
            'message' => 'Default location latitude and longitude are required.',
        ], 422);
    }

    $lat = (float)$latRaw;
    $lng = (float)$lngRaw;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        json_response(['success' => false, 'message' => 'Invalid coordinates.'], 422);
    }

    // Ensure a rider_profiles row exists before updating (some riders never had
    // their profile materialized if they only ever set presence/requirements).
    $ensure = $pdo->prepare(
        "INSERT INTO rider_profiles (user_id, vehicle_type, status, created_at, updated_at)
         VALUES (?, 'motorcycle', 'offline', NOW(), NOW())
         ON DUPLICATE KEY UPDATE updated_at = NOW()"
    );
    $ensure->execute([$riderUserId]);

    $up = $pdo->prepare(
        'UPDATE rider_profiles SET
            home_address_line1 = :a1,
            home_barangay = :bg,
            home_city = :city,
            home_latitude = :lat,
            home_longitude = :lng
         WHERE user_id = :uid'
    );
    $up->execute([
        'a1' => $line1 !== '' ? $line1 : null,
        'bg' => $barangay !== '' ? $barangay : null,
        'city' => $city !== '' ? $city : null,
        'lat' => $lat,
        'lng' => $lng,
        'uid' => $riderUserId,
    ]);

    json_response([
        'success' => true,
        'message' => 'Default location saved.',
        'data' => [
            'user_id' => $riderUserId,
            'home_address_line1' => $line1 !== '' ? $line1 : null,
            'home_barangay' => $barangay !== '' ? $barangay : null,
            'home_city' => $city !== '' ? $city : null,
            'home_latitude' => $lat,
            'home_longitude' => $lng,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to save default location.',
        'error' => $e->getMessage(),
    ], 400);
}
