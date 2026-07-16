<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    $buyerUserId = (int)($_GET['buyer_user_id'] ?? 0);
    $riderUserId = (int)($_GET['rider_user_id'] ?? 0);
    sukiwave_require_pabili_pair_auth($pdo, $buyerUserId, $riderUserId);

    $st = $pdo->prepare(
        'SELECT COALESCE(MAX(session_id), 0) AS max_sess
         FROM pabili_chat_messages
         WHERE buyer_user_id = ?
           AND rider_user_id = ?'
    );
    $st->execute([$buyerUserId, $riderUserId]);
    $row = $st->fetch();
    $max = (int)($row['max_sess'] ?? 0);

    $sessionId = $max > 0
        ? $max
        : sukiwave_pabili_bootstrap_session_id($buyerUserId, $riderUserId);

    $sessionRow = sukiwave_pabili_session_row(
        $pdo,
        $buyerUserId,
        $riderUserId,
        $sessionId
    );
    $sessionPayload = sukiwave_pabili_session_status_payload($sessionRow);
    $sessionPayload['session_id'] = $sessionId;

    json_response([
        'success' => true,
        'data' => [
            'session_id' => $sessionId,
            'had_messages' => $max > 0,
            'session' => $sessionPayload,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to resolve session.',
        'error' => $e->getMessage(),
    ], 400);
}
