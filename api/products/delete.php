<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$productId = (int)($input['product_id'] ?? 0);

if ($productId <= 0) {
    json_response(['success' => false, 'message' => 'product_id is required.'], 422);
}

$pdo = db();

try {
    $sellerUserId = sukiwave_require_seller_from_bearer($pdo);

    $stmt = $pdo->prepare(
        'DELETE FROM products WHERE id = ? AND seller_user_id = ?'
    );
    $stmt->execute([$productId, $sellerUserId]);
    if ($stmt->rowCount() === 0) {
        json_response(['success' => false, 'message' => 'Product not found.'], 404);
    }

    json_response([
        'success' => true,
        'message' => 'Product deleted.',
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to delete product.',
        'error' => $e->getMessage(),
    ], 400);
}
