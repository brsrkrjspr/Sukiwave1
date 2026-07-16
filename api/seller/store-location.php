<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

$pdo = db();

if (!sukiwave_seller_profiles_has_store_location_columns($pdo)) {
    json_response([
        'success' => false,
        'message' => 'Database migration required: seller store location columns are missing.',
    ], 503);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sellerUserId = isset($_GET['seller_user_id']) ? (int)$_GET['seller_user_id'] : 0;
    if ($sellerUserId <= 0) {
        json_response(['success' => false, 'message' => 'seller_user_id is required.'], 422);
    }
    try {
        sukiwave_require_seller($pdo, $sellerUserId);
        $storeImageSelect = sukiwave_seller_profiles_has_store_image_column($pdo)
            ? 'store_image_url'
            : 'NULL AS store_image_url';
        $st = $pdo->prepare(
            'SELECT shop_name,
                    ' . $storeImageSelect . ',
                    store_address_line1, store_barangay, store_city,
                    store_latitude, store_longitude
             FROM seller_profiles
             WHERE user_id = ?
             LIMIT 1'
        );
        $st->execute([$sellerUserId]);
        $row = $st->fetch();
        if (!$row) {
            json_response(['success' => false, 'message' => 'Seller profile not found.'], 404);
        }
        json_response(['success' => true, 'data' => $row]);
    } catch (Throwable $e) {
        json_response([
            'success' => false,
            'message' => $e->getMessage(),
        ], 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$line1 = trim((string)($input['store_address_line1'] ?? $input['address_line1'] ?? ''));
$barangay = trim((string)($input['store_barangay'] ?? $input['barangay'] ?? ''));
$city = trim((string)($input['store_city'] ?? $input['city'] ?? ''));
$latRaw = $input['store_latitude'] ?? $input['latitude'] ?? null;
$lngRaw = $input['store_longitude'] ?? $input['longitude'] ?? null;

if ($latRaw === null || $lngRaw === null || $latRaw === '' || $lngRaw === '') {
    json_response(['success' => false, 'message' => 'Store latitude and longitude are required.'], 422);
}

$lat = (float)$latRaw;
$lng = (float)$lngRaw;
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    json_response(['success' => false, 'message' => 'Invalid coordinates.'], 422);
}

try {
    $sellerUserId = sukiwave_require_seller_from_bearer($pdo);

    $up = $pdo->prepare(
        'UPDATE seller_profiles SET
            store_address_line1 = :a1,
            store_barangay = :bg,
            store_city = :city,
            store_latitude = :lat,
            store_longitude = :lng
         WHERE user_id = :uid'
    );
    $up->execute([
        'a1' => $line1 !== '' ? $line1 : null,
        'bg' => $barangay !== '' ? $barangay : null,
        'city' => $city !== '' ? $city : null,
        'lat' => $lat,
        'lng' => $lng,
        'uid' => $sellerUserId,
    ]);

    json_response([
        'success' => true,
        'message' => 'Store location saved.',
        'data' => [
            'store_address_line1' => $line1 !== '' ? $line1 : null,
            'store_barangay' => $barangay !== '' ? $barangay : null,
            'store_city' => $city !== '' ? $city : null,
            'store_latitude' => $lat,
            'store_longitude' => $lng,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to save store location.',
        'error' => $e->getMessage(),
    ], 400);
}
