<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

$pdo = db();
if (!sukiwave_seller_profiles_has_settings_columns($pdo)) {
    json_response([
        'success' => false,
        'message' => 'Database migration required: seller profile settings columns are missing.',
    ], 503);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    $sellerUserId = sukiwave_require_seller_from_bearer($pdo);

    if ($method === 'GET') {
        $st = $pdo->prepare(
            'SELECT user_id, shop_name, store_description, operating_days_json, store_image_url
             FROM seller_profiles
             WHERE user_id = ?
             LIMIT 1'
        );
        $st->execute([$sellerUserId]);
        $row = $st->fetch();
        if (!$row) {
            json_response(['success' => false, 'message' => 'Seller profile not found.'], 404);
        }
        json_response([
            'success' => true,
            'data' => [
                'user_id' => (int)$row['user_id'],
                'shop_name' => (string)($row['shop_name'] ?? ''),
                'store_description' => (string)($row['store_description'] ?? ''),
                'operating_days_json' => $row['operating_days_json'] !== null ? (string)$row['operating_days_json'] : null,
                'store_image_url' => $row['store_image_url'] !== null ? (string)$row['store_image_url'] : null,
            ],
        ]);
    }

    if ($method !== 'POST') {
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $input = read_json_body();
    $shopName = trim((string)($input['shop_name'] ?? ''));
    $storeDescription = trim((string)($input['store_description'] ?? ''));
    $operatingDays = $input['operating_days'] ?? [];
    $imageUrl = trim((string)($input['store_image_url'] ?? ''));

    if (!is_array($operatingDays)) {
        $operatingDays = [];
    }
    $days = array_values(array_filter(array_map(
        static fn($d) => trim((string)$d),
        $operatingDays
    ), static fn($d) => $d !== ''));
    $daysJson = $days === [] ? null : json_encode($days, JSON_UNESCAPED_UNICODE);

    $up = $pdo->prepare(
        'UPDATE seller_profiles
         SET shop_name = :shop_name,
             store_description = :store_description,
             operating_days_json = :operating_days_json,
             store_image_url = COALESCE(:store_image_url, store_image_url)
         WHERE user_id = :user_id'
    );
    $up->execute([
        'shop_name' => $shopName !== '' ? $shopName : null,
        'store_description' => $storeDescription !== '' ? $storeDescription : null,
        'operating_days_json' => $daysJson,
        'store_image_url' => $imageUrl !== '' ? $imageUrl : null,
        'user_id' => $sellerUserId,
    ]);

    $st = $pdo->prepare(
        'SELECT user_id, shop_name, store_description, operating_days_json, store_image_url
         FROM seller_profiles
         WHERE user_id = ?
         LIMIT 1'
    );
    $st->execute([$sellerUserId]);
    $row = $st->fetch();

    json_response([
        'success' => true,
        'message' => 'Seller profile updated.',
        'data' => [
            'user_id' => (int)($row['user_id'] ?? $sellerUserId),
            'shop_name' => (string)($row['shop_name'] ?? ''),
            'store_description' => (string)($row['store_description'] ?? ''),
            'operating_days_json' => $row['operating_days_json'] !== null ? (string)$row['operating_days_json'] : null,
            'store_image_url' => $row['store_image_url'] !== null ? (string)$row['store_image_url'] : null,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Seller profile request failed.',
        'error' => $e->getMessage(),
    ], 400);
}

