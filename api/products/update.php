<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$productId = (int)($input['product_id'] ?? 0);
$name = trim((string)($input['name'] ?? ''));
$price = (float)($input['price'] ?? 0);
$stock = (int)($input['stock'] ?? 0);
$emoji = array_key_exists('emoji', $input)
    ? trim((string)$input['emoji'])
    : '';
$hasImageUrl = array_key_exists('image_url', $input);
$imageUrl = trim((string)($input['image_url'] ?? ''));
$sellerType = isset($input['seller_type']) ? trim((string)$input['seller_type']) : null;
$categoryName = isset($input['category_name']) ? trim((string)$input['category_name']) : null;
$status = trim((string)($input['status'] ?? 'Active'));
if ($status !== 'Active' && $status !== 'Inactive') {
    $status = 'Active';
}

if ($productId <= 0 || $name === '') {
    json_response(['success' => false, 'message' => 'product_id and name are required.'], 422);
}

if ($price < 0) {
    json_response(['success' => false, 'message' => 'Invalid price.'], 422);
}

if ($stock < 0) {
    json_response(['success' => false, 'message' => 'Invalid stock.'], 422);
}

$pdo = db();

try {
    $sellerUserId = sukiwave_require_seller_from_bearer($pdo);
    $hasImageColumn = sukiwave_products_has_image_url_column($pdo);

    $own = $pdo->prepare(
        'SELECT id FROM products WHERE id = ? AND seller_user_id = ? LIMIT 1'
    );
    $own->execute([$productId, $sellerUserId]);
    if (!$own->fetch()) {
        json_response(['success' => false, 'message' => 'Product not found.'], 404);
    }

    $categoryId = sukiwave_category_id_for_name($pdo, $categoryName);
    if ($categoryId === null) {
        $categoryId = sukiwave_category_id_for_seller_type($pdo, $sellerType);
    }

    $set = [
        'category_id = :category_id',
        'name = :name',
        'price = :price',
        'stock = :stock',
        'status = :status',
        'emoji = :emoji',
    ];
    $params = [
        'category_id' => $categoryId,
        'name' => $name,
        'price' => $price,
        'stock' => $stock,
        'status' => $status,
        'emoji' => $emoji !== '' ? $emoji : null,
        'id' => $productId,
        'seller_user_id' => $sellerUserId,
    ];
    if ($hasImageUrl && $hasImageColumn) {
        $set[] = 'image_url = :image_url';
        $params['image_url'] = $imageUrl !== '' ? $imageUrl : null;
    }
    $sql = 'UPDATE products SET ' . implode(', ', $set) .
        ' WHERE id = :id AND seller_user_id = :seller_user_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    json_response([
        'success' => true,
        'message' => 'Product updated.',
        'data' => ['id' => $productId],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to update product.',
        'error' => $e->getMessage(),
    ], 400);
}
