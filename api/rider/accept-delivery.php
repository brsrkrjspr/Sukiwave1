<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$orderId = (int)($input['order_id'] ?? 0);
$assignmentNote = trim((string)($input['assignment_note'] ?? ''));

if ($orderId <= 0) {
    json_response(['success' => false, 'message' => 'order_id is required.'], 422);
}

$pdo = db();

try {
    $riderUserId = sukiwave_require_rider_from_bearer($pdo);
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare(
        "SELECT id, order_code, status
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
    // Keep in sync with rider/available-orders.php: riders may see orders before
    // the seller marks "Ready for Pickup" (demo / early assignment flows).
    $acceptableOrderStatuses = [
        'New',
        'Preparing',
        'Ready for Pickup',
        'Assigned to Rider',
    ];
    if (!in_array($orderStatus, $acceptableOrderStatuses, true)) {
        throw new RuntimeException("Order is not ready for rider assignment (status: {$orderStatus}).");
    }

    $deliveryStmt = $pdo->prepare(
        "SELECT id, rider_user_id, status
         FROM deliveries
         WHERE order_id = :order_id
         LIMIT 1
         FOR UPDATE"
    );
    $deliveryStmt->execute(['order_id' => $orderId]);
    $delivery = $deliveryStmt->fetch();

    if (!$delivery) {
        $insertDelivery = $pdo->prepare(
            "INSERT INTO deliveries (
                order_id, rider_user_id, status, assignment_note, assigned_at, accepted_at
             ) VALUES (
                :order_id, :rider_user_id, 'accepted', :assignment_note, NOW(), NOW()
             )"
        );
        $insertDelivery->execute([
            'order_id' => $orderId,
            'rider_user_id' => $riderUserId,
            'assignment_note' => $assignmentNote !== '' ? $assignmentNote : null,
        ]);
        $deliveryId = (int)$pdo->lastInsertId();
        $deliveryStatus = 'accepted';
    } else {
        $existingRider = isset($delivery['rider_user_id']) ? (int)$delivery['rider_user_id'] : 0;
        $existingStatus = (string)$delivery['status'];
        $deliveryId = (int)$delivery['id'];

        if ($existingRider !== 0 && $existingRider !== $riderUserId) {
            throw new RuntimeException('Delivery is already assigned to another rider.');
        }

        if (in_array($existingStatus, ['picked_up', 'in_transit', 'delivered'], true)) {
            throw new RuntimeException("Cannot accept delivery in status '{$existingStatus}'.");
        }

        $updateDelivery = $pdo->prepare(
            "UPDATE deliveries
             SET rider_user_id = :rider_user_id,
                 status = 'accepted',
                 assignment_note = COALESCE(:assignment_note, assignment_note),
                 assigned_at = COALESCE(assigned_at, NOW()),
                 accepted_at = NOW()
             WHERE id = :id"
        );
        $updateDelivery->execute([
            'rider_user_id' => $riderUserId,
            'assignment_note' => $assignmentNote !== '' ? $assignmentNote : null,
            'id' => $deliveryId,
        ]);
        $deliveryStatus = 'accepted';
    }

    $pdo->prepare(
        "UPDATE rider_profiles
         SET status = 'on_delivery', last_seen_at = NOW()
         WHERE user_id = :user_id"
    )->execute(['user_id' => $riderUserId]);

    $pdo->prepare(
        "UPDATE orders
         SET status = 'Assigned to Rider'
         WHERE id = :id AND status IN ('New', 'Preparing', 'Ready for Pickup', 'Assigned to Rider')"
    )->execute(['id' => $orderId]);

    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'Delivery accepted.',
        'data' => [
            'delivery_id' => $deliveryId,
            'order_id' => $orderId,
            'order_code' => (string)$order['order_code'],
            'rider_user_id' => $riderUserId,
            'delivery_status' => $deliveryStatus,
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'success' => false,
        'message' => 'Failed to accept delivery.',
        'error' => $e->getMessage(),
    ], 400);
}
