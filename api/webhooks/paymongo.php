<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paymongo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') {
    json_response(['success' => false, 'message' => 'Empty body.'], 400);
}

$webhookSecret = paymongo_webhook_secret();
if ($webhookSecret === '') {
    error_log('paymongo webhook: PAYMONGO_WEBHOOK_SECRET is not set');
    json_response(['success' => false, 'message' => 'Webhook not configured.'], 503);
}

$sigHeader = paymongo_extract_signature_header();
if ($sigHeader === '') {
    json_response(['success' => false, 'message' => 'Missing signature.'], 400);
}

if (!paymongo_webhook_timestamp_skew_ok($sigHeader, 600)) {
    json_response(['success' => false, 'message' => 'Stale timestamp.'], 400);
}

if (!paymongo_verify_webhook_signature($raw, $sigHeader, $webhookSecret)) {
    json_response(['success' => false, 'message' => 'Invalid signature.'], 400);
}

$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    json_response(['success' => false, 'message' => 'Invalid JSON.'], 400);
}

$event = paymongo_parse_checkout_paid_event($decoded);
if ($event === null) {
    json_response(['received' => true, 'ignored' => true], 200);
}

$sessionId = $event['checkout_session_id'];
$pdo = db();

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare(
        'SELECT op.id AS payment_row_id, op.order_id, o.payment_status
         FROM order_payments op
         INNER JOIN orders o ON o.id = op.order_id
         WHERE op.provider = :provider
           AND op.provider_checkout_session_id = :sid
         FOR UPDATE'
    );
    $st->execute([
        'provider' => 'paymongo',
        'sid' => $sessionId,
    ]);
    $row = $st->fetch();
    if (!$row) {
        $pdo->rollBack();
        json_response(['received' => true, 'note' => 'unknown session'], 200);
    }
    $orderId = (int)$row['order_id'];
    $paymentRowId = (int)$row['payment_row_id'];
    $current = strtolower((string)$row['payment_status']);

    if ($current === 'paid') {
        $pdo->commit();
        json_response(['received' => true], 200);
    }

    $updOrder = $pdo->prepare(
        'UPDATE orders
         SET payment_status = :paid, updated_at = CURRENT_TIMESTAMP
         WHERE id = :oid
           AND payment_status <> :paid2'
    );
    $updOrder->execute([
        'paid' => 'paid',
        'paid2' => 'paid',
        'oid' => $orderId,
    ]);

    $updPay = $pdo->prepare(
        'UPDATE order_payments
         SET status = :st, updated_at = CURRENT_TIMESTAMP
         WHERE id = :pid'
    );
    $updPay->execute([
        'st' => 'paid',
        'pid' => $paymentRowId,
    ]);

    $pdo->commit();
    json_response(['received' => true], 200);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('paymongo webhook: ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'Processing failed.'], 500);
}
