<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

// Riders pick up at the seller's store first; require coordinates when the column exists.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$buyerUserId = 0;
$sellerUserId = (int)($input['seller_user_id'] ?? 0);
$addressId = isset($input['address_id']) ? (int)$input['address_id'] : null;
$idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
$paymentMethod = strtolower(trim((string)($input['payment_method'] ?? 'cash')));
$notes = trim((string)($input['notes'] ?? ''));
$items = is_array($input['items'] ?? null) ? $input['items'] : [];

if (!in_array($paymentMethod, ['cash', 'paymongo'], true)) {
    json_response(['success' => false, 'message' => 'Invalid payment_method. Use cash or paymongo.'], 422);
}

if ($sellerUserId <= 0 || count($items) === 0) {
    json_response(['success' => false, 'message' => 'Missing required order payload.'], 422);
}

$pdo = db();

try {
    $buyerUserId = sukiwave_require_buyer_from_bearer($pdo);
    if ($idempotencyKey !== '') {
        $dup = $pdo->prepare(
            'SELECT id, order_code, total_amount
             FROM orders
             WHERE buyer_user_id = :buyer_user_id
               AND notes LIKE :idempotency_note
             ORDER BY id DESC
             LIMIT 1'
        );
        $dup->execute([
            'buyer_user_id' => $buyerUserId,
            'idempotency_note' => '[idempotency:' . $idempotencyKey . ']%',
        ]);
        $existing = $dup->fetch();
        if ($existing) {
            json_response([
                'success' => true,
                'message' => 'Order already created for this request.',
                'data' => [
                    'order_id' => (int)$existing['id'],
                    'order_code' => (string)$existing['order_code'],
                    'total_amount' => (float)$existing['total_amount'],
                    'replayed' => true,
                ],
            ], 200);
        }
    }
    $pdo->beginTransaction();
    sukiwave_require_seller($pdo, $sellerUserId);

    $subtotal = 0.0;
    $preparedItems = [];

    $productStmt = $pdo->prepare(
        "SELECT id, name, price, stock
         FROM products
         WHERE id = :id
         FOR UPDATE"
    );

    if (sukiwave_seller_profiles_has_store_location_columns($pdo)) {
        sukiwave_require_seller($pdo, $sellerUserId);
        $locStmt = $pdo->prepare(
            'SELECT store_latitude, store_longitude FROM seller_profiles WHERE user_id = ? LIMIT 1'
        );
    if ($addressId !== null && $addressId > 0) {
        $addrStmt = $pdo->prepare(
            'SELECT id FROM buyer_addresses WHERE id = :id AND buyer_user_id = :buyer_user_id LIMIT 1'
        );
        $addrStmt->execute([
            'id' => $addressId,
            'buyer_user_id' => $buyerUserId,
        ]);
        if (!$addrStmt->fetch()) {
            throw new RuntimeException('Selected address does not belong to this buyer.');
        }
    }

        $locStmt->execute([$sellerUserId]);
        $loc = $locStmt->fetch();
        if (
            !$loc
            || $loc['store_latitude'] === null
            || $loc['store_longitude'] === null
        ) {
            throw new RuntimeException(
                'This seller has not set a store pickup location yet. Please ask them to add it in Seller Dashboard → Settings before taking orders.'
            );
        }
    }

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('Invalid item payload.');
        }

        $productStmt->execute(['id' => $productId]);
        $product = $productStmt->fetch();
        if (!$product) {
            throw new RuntimeException("Product {$productId} not found.");
        }
        if ((int)$product['stock'] < $qty) {
            throw new RuntimeException("Insufficient stock for {$product['name']}.");
        }

        $unitPrice = (float)$product['price'];
        $lineTotal = $unitPrice * $qty;
        $subtotal += $lineTotal;

        $preparedItems[] = [
            'product_id' => (int)$product['id'],
            'name' => (string)$product['name'],
            'unit_price' => $unitPrice,
            'quantity' => $qty,
            'line_total' => $lineTotal,
        ];
    }

    $deliveryFee = $subtotal >= 499 ? 0.0 : 59.0;
    $discount = 0.0;
    $total = $subtotal + $deliveryFee - $discount;
    $orderCode = 'SW' . date('YmdHis') . random_int(10, 99);

    $orderStmt = $pdo->prepare(
        "INSERT INTO orders (
            order_code, buyer_user_id, seller_user_id, address_id,
            subtotal, delivery_fee, discount_amount, total_amount,
            payment_method, payment_status, status, notes
         ) VALUES (
            :order_code, :buyer_user_id, :seller_user_id, :address_id,
            :subtotal, :delivery_fee, :discount_amount, :total_amount,
            :payment_method, 'unpaid', 'New', :notes
         )"
    );
    $orderStmt->execute([
        'order_code' => $orderCode,
        'buyer_user_id' => $buyerUserId,
        'seller_user_id' => $sellerUserId,
        'address_id' => $addressId,
        'subtotal' => $subtotal,
        'delivery_fee' => $deliveryFee,
        'discount_amount' => $discount,
        'total_amount' => $total,
        'payment_method' => $paymentMethod,
        'notes' => trim(
            ($idempotencyKey !== '' ? '[idempotency:' . $idempotencyKey . ']' : '') .
            (($notes !== '' && $idempotencyKey !== '') ? ' ' : '') .
            $notes
        ) !== ''
            ? trim(
                ($idempotencyKey !== '' ? '[idempotency:' . $idempotencyKey . ']' : '') .
                (($notes !== '' && $idempotencyKey !== '') ? ' ' : '') .
                $notes
            )
            : null,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        "INSERT INTO order_items (
            order_id, product_id, product_name_snapshot,
            unit_price, quantity, line_total
         ) VALUES (
            :order_id, :product_id, :product_name_snapshot,
            :unit_price, :quantity, :line_total
         )"
    );
    $stockStmt = $pdo->prepare(
        "UPDATE products
         SET stock = stock - :qty
         WHERE id = :id"
    );

    foreach ($preparedItems as $it) {
        $itemStmt->execute([
            'order_id' => $orderId,
            'product_id' => $it['product_id'],
            'product_name_snapshot' => $it['name'],
            'unit_price' => $it['unit_price'],
            'quantity' => $it['quantity'],
            'line_total' => $it['line_total'],
        ]);
        $stockStmt->execute([
            'qty' => $it['quantity'],
            'id' => $it['product_id'],
        ]);
    }

    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'Order created successfully.',
        'data' => [
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'total_amount' => $total,
        ],
    ], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response([
        'success' => false,
        'message' => 'Failed to create order.',
        'error' => $e->getMessage(),
    ], 400);
}

