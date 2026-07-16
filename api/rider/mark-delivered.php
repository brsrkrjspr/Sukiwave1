<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$orderId = (int)($input['order_id'] ?? 0);
$riderNote = trim((string)($input['rider_note'] ?? ''));

if ($orderId <= 0) {
    json_response(['success' => false, 'message' => 'order_id is required.'], 422);
}

$pdo = db();

try {
    $riderUserId = sukiwave_require_rider_from_bearer($pdo);
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT id, status, rider_user_id
         FROM deliveries
         WHERE order_id = :order_id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute(['order_id' => $orderId]);
    $delivery = $stmt->fetch();

    if (!$delivery) {
        throw new RuntimeException('Delivery not found for this order.');
    }

    $assignedRider = isset($delivery['rider_user_id']) ? (int)$delivery['rider_user_id'] : 0;
    if ($assignedRider !== $riderUserId) {
        throw new RuntimeException('This delivery is not assigned to the provided rider.');
    }

    $status = (string)$delivery['status'];
    if (!in_array($status, ['picked_up', 'in_transit', 'delivered'], true)) {
        throw new RuntimeException("Cannot mark delivered from status '{$status}'.");
    }

    $orderStmt = $pdo->prepare(
        "SELECT status
         FROM orders
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $orderStmt->execute(['id' => $orderId]);
    $order = $orderStmt->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    $orderStatus = (string)($order['status'] ?? '');
    if (!in_array($orderStatus, ['Out for Delivery', 'Delivered'], true)) {
        throw new RuntimeException("Order is not out for delivery (status: {$orderStatus}).");
    }

    $pdo->prepare(
        "UPDATE deliveries
         SET status = 'delivered',
             rider_note = COALESCE(:rider_note, rider_note),
             delivered_at = COALESCE(delivered_at, NOW())
         WHERE id = :id"
    )->execute([
        'rider_note' => $riderNote !== '' ? $riderNote : null,
        'id' => (int)$delivery['id'],
    ]);

    $pdo->prepare(
        "UPDATE orders
         SET status = 'Delivered'
         WHERE id = :id"
    )->execute(['id' => $orderId]);

    $pdo->prepare(
        "UPDATE rider_profiles
         SET status = 'available', last_seen_at = NOW()
         WHERE user_id = :user_id"
    )->execute(['user_id' => $riderUserId]);

    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'Order marked as delivered.',
        'data' => [
            'order_id' => $orderId,
            'rider_user_id' => $riderUserId,
            'delivery_status' => 'delivered',
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'success' => false,
        'message' => 'Failed to mark delivered.',
        'error' => $e->getMessage(),
    ], 400);
}
