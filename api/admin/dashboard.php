<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

try {
    $pdo = db();
    $input = read_json_body();
    sukiwave_resolve_admin_identity($pdo, $input);

    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $totalOrders = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $newOrdersCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status = 'New'"
    )->fetchColumn();
    $ordersLast24h = (int)$pdo->query(
        'SELECT COUNT(*) FROM orders WHERE created_at >= (NOW() - INTERVAL 1 DAY)'
    )->fetchColumn();

    // Extend with server-side checks later (disk, queue, etc.).
    $warnings = [];

    json_response([
        'success' => true,
        'data' => [
            'total_users' => $totalUsers,
            'total_orders' => $totalOrders,
            'new_orders_count' => $newOrdersCount,
            'orders_last_24h' => $ordersLast24h,
            'warnings' => $warnings,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Admin dashboard request failed.',
        'error' => $e->getMessage(),
    ], 400);
}
