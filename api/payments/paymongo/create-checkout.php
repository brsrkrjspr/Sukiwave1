<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth_helpers.php';
require_once __DIR__ . '/../../config/paymongo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$orderId = (int)($input['order_id'] ?? 0);
$successUrl = trim((string)($input['success_url'] ?? ''));
$cancelUrl = trim((string)($input['cancel_url'] ?? ''));

if ($orderId <= 0) {
    json_response(['success' => false, 'message' => 'order_id is required.'], 422);
}
if ($successUrl === '' || $cancelUrl === '') {
    json_response(['success' => false, 'message' => 'success_url and cancel_url are required.'], 422);
}
if (!paymongo_is_allowed_return_url($successUrl) || !paymongo_is_allowed_return_url($cancelUrl)) {
    json_response([
        'success' => false,
        'message' => 'success_url and cancel_url must be valid https URLs, or http for localhost / 127.0.0.1 / 10.0.2.2 (emulator) only.',
    ], 422);
}

$pdo = db();

try {
    $buyerUserId = sukiwave_require_buyer_from_bearer($pdo);
    $pdo->beginTransaction();
    $st = $pdo->prepare(
        'SELECT id, order_code, buyer_user_id, payment_method, payment_status, total_amount
         FROM orders
         WHERE id = :id
         FOR UPDATE'
    );
    $st->execute(['id' => $orderId]);
    $order = $st->fetch();
    if (!$order) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'Order not found.'], 404);
    }
    if ((int)$order['buyer_user_id'] !== $buyerUserId) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'Forbidden.'], 403);
    }
    if (strtolower((string)$order['payment_method']) !== 'paymongo') {
        $pdo->rollBack();
        json_response([
            'success' => false,
            'message' => 'This order is not using PayMongo. Create the order with payment_method paymongo.',
        ], 422);
    }
    if (strtolower((string)$order['payment_status']) === 'paid') {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'Order is already paid.'], 409);
    }

    $total = (float)$order['total_amount'];
    $centavos = paymongo_peso_to_centavos($total);
    $orderCode = (string)$order['order_code'];
    $description = 'SukiWave order ' . $orderCode;

    $pmResponse = paymongo_create_checkout_session(
        $centavos,
        'PHP',
        $description,
        ['order_id' => (string)$orderId],
        $successUrl,
        $cancelUrl,
        paymongo_default_payment_method_types()
    );
    $parsed = paymongo_parse_checkout_session_create_response($pmResponse);
    $sessionId = $parsed['checkout_session_id'];
    $checkoutUrl = $parsed['checkout_url'];

    $ins = $pdo->prepare(
        'INSERT INTO order_payments (
            order_id, provider, provider_checkout_session_id, status,
            amount_centavos, currency, checkout_url, raw_create_json
         ) VALUES (
            :order_id, :provider, :session_id, :status,
            :amount_centavos, :currency, :checkout_url, :raw_json
         )'
    );
    $ins->execute([
        'order_id' => $orderId,
        'provider' => 'paymongo',
        'session_id' => $sessionId,
        'status' => 'created',
        'amount_centavos' => $centavos,
        'currency' => 'PHP',
        'checkout_url' => $checkoutUrl,
        'raw_json' => json_encode($pmResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'Checkout session created.',
        'data' => [
            'order_id' => $orderId,
            'checkout_url' => $checkoutUrl,
            'checkout_session_id' => $sessionId,
        ],
    ], 200);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
