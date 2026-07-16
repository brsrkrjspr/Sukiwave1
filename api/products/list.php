<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

try {
    $sellerScope = isset($_GET['seller_user_id']) ? (int)$_GET['seller_user_id'] : 0;
    $forSeller = isset($_GET['for_seller']) && $_GET['for_seller'] === '1';

    $where = [];
    $params = [];

    if ($sellerScope > 0 && $forSeller) {
        $where[] = 'p.seller_user_id = :sid';
        $params['sid'] = $sellerScope;
    } else {
        $where[] = "p.status = 'Active'";
        $where[] = 'p.stock > 0';
    }

    $pdo = db();
    $imageCol = sukiwave_products_has_image_url_column($pdo)
        ? 'p.image_url'
        : 'NULL AS image_url';

    $sql = 'SELECT
            p.id,
            p.seller_user_id,
            p.name,
            COALESCE(c.name, \'Uncategorized\') AS category,
            p.price,
            p.stock,
            p.status,
            COALESCE(p.emoji, \'🛍️\') AS emoji,
            ' . $imageCol . ',
            COALESCE(sp.shop_name, u.full_name, \'Seller\') AS seller
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN users u ON u.id = p.seller_user_id
         LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_user_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY p.updated_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    json_response([
        'success' => true,
        'count' => count($rows),
        'data' => $rows,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to fetch products.',
        'error' => $e->getMessage(),
    ], 500);
}
