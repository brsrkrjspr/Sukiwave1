<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    $input = read_json_body();
    $buyerUserId = (int)($input['buyer_user_id'] ?? 0);
    $riderUserId = (int)($input['rider_user_id'] ?? 0);
    $auth = sukiwave_require_pabili_pair_auth($pdo, $buyerUserId, $riderUserId);

    $sessionId = (int)($input['session_id'] ?? 0);
    if ($sessionId <= 0) {
        throw new RuntimeException('session_id is required.');
    }

    $body = trim((string)($input['body'] ?? ''));
    $imageUrl = trim((string)($input['image_url'] ?? ''));
    if ($imageUrl !== '' && strlen($imageUrl) > 1000) {
        throw new RuntimeException('Image URL is too long.');
    }
    if ($body === '' && $imageUrl === '') {
        throw new RuntimeException('Message text or image is required.');
    }
    if (strlen($body) > 8000) {
        throw new RuntimeException('Message is too long.');
    }

    $stable = sukiwave_pabili_stable_conversation_id($buyerUserId, $riderUserId);
    $senderRole = $auth['sender_role'];
    $senderUserId = $auth['sender_user_id'];

    // Refuse to append messages once the session is completed. The chat input
    // is disabled client-side, but stale clients (or scripts) must not be able
    // to bypass it. Completed conversations are read-only and surfaced in the
    // Pabili History tab.
    $sessionRow = sukiwave_pabili_session_row(
        $pdo,
        $buyerUserId,
        $riderUserId,
        $sessionId
    );
    if ($sessionRow !== null && ($sessionRow['pabili_status'] ?? '') === 'completed') {
        json_response([
            'success' => false,
            'message' => 'This Pabili transaction is already completed.',
            'data' => array_merge(
                ['session_id' => $sessionId],
                sukiwave_pabili_session_status_payload($sessionRow),
            ),
        ], 409);
    }

    $ins = $pdo->prepare(
        'INSERT INTO pabili_chat_messages
            (stable_conversation_id, buyer_user_id, rider_user_id, session_id, sender_role, sender_user_id, body, image_url, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $ins->execute([
        $stable,
        $buyerUserId,
        $riderUserId,
        $sessionId,
        $senderRole,
        $senderUserId,
        $body,
        $imageUrl === '' ? null : $imageUrl,
    ]);
    $newId = (int)$pdo->lastInsertId();
    $st = $pdo->prepare(
        'SELECT id, stable_conversation_id, buyer_user_id, rider_user_id, session_id,
                sender_role, sender_user_id, body, image_url, created_at
         FROM pabili_chat_messages
         WHERE id = ?
         LIMIT 1'
    );
    $st->execute([$newId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Failed to load new message.');
    }

    json_response([
        'success' => true,
        'data' => $row,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to send message.',
        'error' => $e->getMessage(),
    ], 400);
}
