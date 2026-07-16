<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

try {
    $pdo = db();
    $input = read_json_body();
    sukiwave_resolve_admin_identity($pdo, $input);

    $kind = strtolower(trim((string)($_GET['kind'] ?? 'users')));
    if ($kind !== 'users' && $kind !== 'orders') {
        json_response(['success' => false, 'message' => 'kind must be users or orders.'], 422);
    }

    $safeKind = preg_replace('/[^a-z0-9_-]/', '', $kind) ?: 'export';

    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="sukiwave_' . $safeKind . '_export.csv"'
    );

    $out = fopen('php://output', 'w');
    if ($out === false) {
        throw new RuntimeException('Unable to open output stream.');
    }

    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($kind === 'users') {
        fputcsv($out, ['id', 'role', 'full_name', 'email', 'phone', 'is_active', 'created_at']);
        $st = $pdo->query(
            'SELECT id, role, full_name, email, phone, is_active, created_at
             FROM users ORDER BY id ASC LIMIT 50000'
        );
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, $row);
        }
    } else {
        fputcsv($out, [
            'id',
            'order_code',
            'buyer_user_id',
            'seller_user_id',
            'subtotal',
            'delivery_fee',
            'total_amount',
            'payment_status',
            'status',
            'created_at',
        ]);
        $st = $pdo->query(
            'SELECT id, order_code, buyer_user_id, seller_user_id, subtotal, delivery_fee,
                    total_amount, payment_status, status, created_at
             FROM orders ORDER BY id DESC LIMIT 50000'
        );
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, $row);
        }
    }

    fclose($out);
    exit;
} catch (Throwable $e) {
    if (!headers_sent()) {
        header_remove('Content-Disposition');
        header('Content-Type: application/json; charset=utf-8');
    }
    json_response([
        'success' => false,
        'message' => 'Export failed.',
        'error' => $e->getMessage(),
    ], 400);
}
