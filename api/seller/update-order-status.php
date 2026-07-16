<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$orderId = (int)($input['order_id'] ?? 0);
$status = trim((string)($input['status'] ?? ''));
$note = trim((string)($input['note'] ?? ''));

$allowed = [
    'New',
    'Preparing',
    'Ready for Pickup',
    'Delivered',
    'Cancelled',
];

if ($orderId <= 0 || $status === '' || !in_array($status, $allowed, true)) {
    json_response(['success' => false, 'message' => 'Invalid payload.'], 422);
}

try {
    $pdo = db();
    $sellerUserId = sukiwave_require_seller_from_bearer($pdo);
    $pdo->beginTransaction();

    $check = $pdo->prepare(
        'SELECT id, notes, status FROM orders WHERE id = :id AND seller_user_id = :sid LIMIT 1 FOR UPDATE'
    );
    $check->execute(['id' => $orderId, 'sid' => $sellerUserId]);
    $row = $check->fetch();
    if (!$row) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'Order not found.'], 404);
    }

    $currentStatus = (string)($row['status'] ?? '');
    $allowedNext = match ($currentStatus) {
        'New' => ['Preparing', 'Cancelled'],
        'Preparing' => ['Ready for Pickup', 'Cancelled'],
        'Ready for Pickup' => ['Cancelled'],
        'Assigned to Rider', 'Out for Delivery', 'Delivered', 'Cancelled' => [],
        default => [],
    };
    if ($status === $currentStatus) {
        $allowedNext[] = $currentStatus;
    }
    if (!in_array($status, $allowedNext, true)) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response([
            'success' => false,
            'message' => "Invalid order status transition: {$currentStatus} -> {$status}",
        ], 409);
    }

    $notesCol = (string)($row['notes'] ?? '');
    if ($status === 'Cancelled' && $note !== '') {
        $merged = $notesCol !== ''
            ? $notesCol . "\n[Cancelled] " . $note
            : '[Cancelled] ' . $note;
        $upd = $pdo->prepare(
            'UPDATE orders SET status = :st, notes = :n, updated_at = NOW() WHERE id = :id AND seller_user_id = :sid'
        );
        $upd->execute(['st' => $status, 'n' => $merged, 'id' => $orderId, 'sid' => $sellerUserId]);
    } else {
        $upd = $pdo->prepare(
            'UPDATE orders SET status = :st, updated_at = NOW() WHERE id = :id AND seller_user_id = :sid'
        );
        $upd->execute(['st' => $status, 'id' => $orderId, 'sid' => $sellerUserId]);
    }
    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'Order updated.',
        'data' => ['order_id' => $orderId, 'status' => $status],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
