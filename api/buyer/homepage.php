<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function to_int(mixed $value, int $fallback = 0): int
{
    if (is_int($value)) return $value;
    if (is_numeric($value)) return (int)$value;
    return $fallback;
}

function to_float_or_null(mixed $value): ?float
{
    if ($value === null || $value === '') return null;
    if (is_numeric($value)) return (float)$value;
    return null;
}

function clamp_limit(int $value, int $fallback): int
{
    if ($value <= 0) return $fallback;
    if ($value > 24) return 24;
    return $value;
}

function fetch_recommended_products(PDO $pdo, int $limit): array
{
    $sql = "SELECT
                p.id,
                p.seller_user_id,
                p.name,
                COALESCE(c.name, 'Uncategorized') AS category,
                p.price,
                p.stock,
                p.status,
                COALESCE(p.emoji, '🛍️') AS emoji,
                " . (sukiwave_products_has_image_url_column($pdo) ? 'p.image_url' : 'NULL AS image_url') . ",
                COALESCE(sp.shop_name, u.full_name, 'Seller') AS seller,
                COALESCE(sp.store_address_line1, '') AS store_address_line1,
                COALESCE(sp.store_barangay, '') AS store_barangay,
                COALESCE(sp.store_city, '') AS store_city,
                COALESCE(recent.recent_qty, 0) AS recent_qty
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN users u ON u.id = p.seller_user_id
            LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_user_id
            LEFT JOIN (
                SELECT oi.product_id, SUM(oi.quantity) AS recent_qty
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY oi.product_id
            ) recent ON recent.product_id = p.id
            WHERE p.status = 'Active' AND p.stock > 0
            ORDER BY recent_qty DESC, p.updated_at DESC
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function fetch_nearby_stores(PDO $pdo, int $limit, ?float $lat, ?float $lng): array
{
    $storeImageSelect = sukiwave_seller_profiles_has_store_image_column($pdo)
        ? 'sp.store_image_url'
        : 'NULL AS store_image_url';
    $hasStoreLocationColumns = sukiwave_seller_profiles_has_store_location_columns($pdo);

    // "Stores" section fallback: list sellers with active products
    // even when buyer location or seller coordinates are not yet set.
    if ($lat === null || $lng === null || !$hasStoreLocationColumns) {
        $sql = "SELECT
                    u.id AS seller_user_id,
                    COALESCE(sp.shop_name, u.full_name, 'Seller') AS seller,
                    {$storeImageSelect},
                    COALESCE(sp.store_address_line1, '') AS store_address_line1,
                    COALESCE(sp.store_barangay, '') AS store_barangay,
                    COALESCE(sp.store_city, '') AS store_city,
                    NULL AS store_latitude,
                    NULL AS store_longitude,
                    0 AS distance_km,
                    MIN(p.id) AS sample_product_id,
                    MAX(p.updated_at) AS latest_product_at
                FROM users u
                INNER JOIN products p ON p.seller_user_id = u.id
                LEFT JOIN seller_profiles sp ON sp.user_id = u.id
                WHERE u.role = 'seller'
                  AND p.status = 'Active'
                  AND p.stock > 0
                GROUP BY u.id, seller, sp.store_image_url, sp.store_address_line1, sp.store_barangay, sp.store_city
                ORDER BY latest_product_at DESC
                LIMIT :lim";
        $st = $pdo->prepare($sql);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    $sql = "SELECT
                sp.user_id AS seller_user_id,
                COALESCE(sp.shop_name, u.full_name, 'Seller') AS seller,
                {$storeImageSelect},
                COALESCE(sp.store_address_line1, '') AS store_address_line1,
                COALESCE(sp.store_barangay, '') AS store_barangay,
                COALESCE(sp.store_city, '') AS store_city,
                sp.store_latitude,
                sp.store_longitude,
                ROUND(
                    6371 * ACOS(
                        LEAST(1, GREATEST(-1,
                            COS(RADIANS(:lat)) * COS(RADIANS(sp.store_latitude)) *
                            COS(RADIANS(sp.store_longitude) - RADIANS(:lng)) +
                            SIN(RADIANS(:lat)) * SIN(RADIANS(sp.store_latitude))
                        ))
                    ),
                    2
                ) AS distance_km,
                MIN(p.id) AS sample_product_id
            FROM seller_profiles sp
            INNER JOIN users u ON u.id = sp.user_id
            INNER JOIN products p ON p.seller_user_id = sp.user_id
            WHERE u.role = 'seller'
              AND sp.store_latitude IS NOT NULL
              AND sp.store_longitude IS NOT NULL
              AND p.status = 'Active'
              AND p.stock > 0
            GROUP BY sp.user_id, seller, sp.store_image_url, sp.store_address_line1, sp.store_barangay, sp.store_city, sp.store_latitude, sp.store_longitude
            ORDER BY distance_km ASC
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':lat', $lat);
    $st->bindValue(':lng', $lng);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function fetch_flash_deals(PDO $pdo, int $limit): array
{
    $hasDealsTable = false;
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($db !== false && $db !== null && (string)$db !== '') {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $st->execute([(string)$db, 'homepage_flash_deals']);
            $hasDealsTable = (int)$st->fetchColumn() > 0;
        }
    } catch (Throwable $e) {
        $hasDealsTable = false;
    }

    if ($hasDealsTable) {
        $sql = "SELECT
                    d.id,
                    d.product_id,
                    p.seller_user_id,
                    d.discount_percent,
                    d.title,
                    d.badge_text,
                    d.ends_at,
                    p.name,
                    p.price AS base_price,
                    COALESCE(c.name, 'Uncategorized') AS category,
                    COALESCE(p.emoji, '🛍️') AS emoji,
                    " . (sukiwave_products_has_image_url_column($pdo) ? 'p.image_url' : 'NULL AS image_url') . ",
                    COALESCE(sp.shop_name, u.full_name, 'Seller') AS seller
                FROM homepage_flash_deals d
                INNER JOIN products p ON p.id = d.product_id
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN users u ON u.id = p.seller_user_id
                LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_user_id
                WHERE d.is_active = 1
                ORDER BY COALESCE(d.ends_at, DATE_ADD(NOW(), INTERVAL 365 DAY)) ASC
                LIMIT :lim";
        $st = $pdo->prepare($sql);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        // If the deals table exists but there are no active rows, fall back to
        // generating synthetic flash deals from active products.
        if (!empty($rows)) return $rows;
    }

    // Fallback: generate synthetic flash deals from active products.
    $sql = "SELECT
                p.id AS product_id,
                p.seller_user_id,
                p.name,
                p.price AS base_price,
                COALESCE(c.name, 'Uncategorized') AS category,
                COALESCE(p.emoji, '🛍️') AS emoji,
                " . (sukiwave_products_has_image_url_column($pdo) ? 'p.image_url' : 'NULL AS image_url') . ",
                COALESCE(sp.shop_name, u.full_name, 'Seller') AS seller
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN users u ON u.id = p.seller_user_id
            LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_user_id
            WHERE p.status = 'Active' AND p.stock > 0
            ORDER BY p.updated_at DESC
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    $discountCycle = [10, 15, 20, 12, 18];
    foreach ($rows as $i => &$row) {
        $discount = $discountCycle[$i % count($discountCycle)];
        $row['id'] = $i + 1;
        $row['discount_percent'] = $discount;
        $row['title'] = 'Limited deal';
        $row['badge_text'] = "$discount% OFF";
        $row['ends_at'] = null;
    }
    return $rows;
}

try {
    $pdo = db();
    $section = strtolower(trim((string)($_GET['section'] ?? 'all')));
    $recommendedLimit = clamp_limit((int)($_GET['recommended_limit'] ?? 6), 6);
    $nearbyLimit = clamp_limit((int)($_GET['nearby_limit'] ?? 6), 6);
    $flashLimit = clamp_limit((int)($_GET['flash_limit'] ?? 6), 6);

    $lat = to_float_or_null($_GET['lat'] ?? null);
    $lng = to_float_or_null($_GET['lng'] ?? null);
    $buyerUserId = to_int($_GET['buyer_user_id'] ?? 0, 0);

    if (($lat === null || $lng === null) && $buyerUserId > 0) {
        $st = $pdo->prepare(
            "SELECT latitude, longitude
             FROM buyer_addresses
             WHERE buyer_user_id = :uid
             ORDER BY is_default DESC, updated_at DESC
             LIMIT 1"
        );
        $st->execute(['uid' => $buyerUserId]);
        $addr = $st->fetch();
        if (is_array($addr)) {
            $lat = to_float_or_null($addr['latitude'] ?? null);
            $lng = to_float_or_null($addr['longitude'] ?? null);
        }
    }

    $data = [];
    if ($section === 'all' || $section === 'recommended') {
        $data['recommended'] = fetch_recommended_products($pdo, $recommendedLimit);
    }
    if ($section === 'all' || $section === 'nearby') {
        $data['nearby_stores'] = fetch_nearby_stores($pdo, $nearbyLimit, $lat, $lng);
    }
    if ($section === 'all' || $section === 'flash') {
        $data['flash_deals'] = fetch_flash_deals($pdo, $flashLimit);
    }

    json_response([
        'success' => true,
        'data' => $data,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to fetch buyer homepage sections.',
        'error' => $e->getMessage(),
    ], 500);
}
