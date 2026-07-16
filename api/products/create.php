<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$name = trim((string)($input['name'] ?? ''));
$price = (float)($input['price'] ?? 0);
$stock = (int)($input['stock'] ?? 0);
$emoji = array_key_exists('emoji', $input)
    ? trim((string)$input['emoji'])
    : '';
$imageUrl = trim((string)($input['image_url'] ?? ''));
$sellerType = isset($input['seller_type']) ? trim((string)$input['seller_type']) : null;
$categoryName = isset($input['category_name']) ? trim((string)$input['category_name']) : null;
$status = trim((string)($input['status'] ?? 'Active'));
if ($status !== 'Active' && $status !== 'Inactive') {
    $status = 'Active';
}

if ($name === '') {
    json_response(['success' => false, 'message' => 'name is required.'], 422);
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
    $categoryId = sukiwave_category_id_for_name($pdo, $categoryName);
    if ($categoryId === null) {
        $categoryId = sukiwave_category_id_for_seller_type($pdo, $sellerType);
    }
    $hasImageColumn = sukiwave_products_has_image_url_column($pdo);

    if ($hasImageColumn) {
        $stmt = $pdo->prepare(
            'INSERT INTO products (
                seller_user_id, category_id, name, price, stock, status, emoji, image_url
             ) VALUES (
                :seller_user_id, :category_id, :name, :price, :stock, :status, :emoji, :image_url
             )'
        );
        $stmt->execute([
            'seller_user_id' => $sellerUserId,
            'category_id' => $categoryId,
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
            'status' => $status,
            'emoji' => $emoji !== '' ? $emoji : null,
            'image_url' => $imageUrl !== '' ? $imageUrl : null,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO products (
                seller_user_id, category_id, name, price, stock, status, emoji
             ) VALUES (
                :seller_user_id, :category_id, :name, :price, :stock, :status, :emoji
             )'
        );
        $stmt->execute([
            'seller_user_id' => $sellerUserId,
            'category_id' => $categoryId,
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
            'status' => $status,
            'emoji' => $emoji !== '' ? $emoji : null,
        ]);
    }
    $productId = (int)$pdo->lastInsertId();

    json_response([
        'success' => true,
        'message' => 'Product created.',
        'data' => [
            'id' => $productId,
            'seller_user_id' => $sellerUserId,
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
            'status' => $status,
            'emoji' => $emoji,
            'image_url' => $hasImageColumn && $imageUrl !== '' ? $imageUrl : null,
        ],
    ], 201);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to create product.',
        'error' => $e->getMessage(),
    ], 400);
}
