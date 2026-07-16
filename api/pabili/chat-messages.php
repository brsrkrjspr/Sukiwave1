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

    $stable = sukiwave_pabili_stable_conversation_id($buyerUserId, $riderUserId);
    $sessionId = (int)($_GET['session_id'] ?? 0);
    if ($sessionId <= 0) {
        json_response([
            'success' => false,
            'message' => 'session_id is required.',
        ], 422);
    }
    $sinceId = (int)($_GET['since_id'] ?? 0);
    if ($sinceId < 0) {
        $sinceId = 0;
    }
    $limit = (int)($_GET['limit'] ?? 100);
    if ($limit <= 0) {
        $limit = 100;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $st = $pdo->prepare(
        'SELECT id, stable_conversation_id, buyer_user_id, rider_user_id, session_id,
                sender_role, sender_user_id, body, image_url, created_at
         FROM pabili_chat_messages
         WHERE stable_conversation_id = ?
           AND buyer_user_id = ?
           AND rider_user_id = ?
           AND session_id = ?
           AND id > ?
         ORDER BY id ASC
         LIMIT ' . (string)$limit
    );
    $st->execute([$stable, $buyerUserId, $riderUserId, $sessionId, $sinceId]);
    $rows = $st->fetchAll() ?: [];

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
            'stable_conversation_id' => $stable,
            'messages' => $rows,
            'session' => $sessionPayload,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to load messages.',
        'error' => $e->getMessage(),
    ], 400);
}
