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
    $sessionId = (int)($input['session_id'] ?? 0);
    if ($sessionId <= 0) {
        throw new RuntimeException('session_id is required.');
    }

    $auth = sukiwave_require_pabili_pair_auth($pdo, $buyerUserId, $riderUserId);
    $completedBy = $auth['sender_role']; // 'buyer' or 'rider'

    // Self-heal: deployments that never ran schema.v13 don't have the table.
    sukiwave_ensure_pabili_sessions_table($pdo);

    // Idempotency guard: if pabili_status is already 'completed', short-circuit
    // and return the existing status. Both rider and buyer may click "Mark as
    // Done" simultaneously; the loser must not double-write completed_at.
    $existingSession = sukiwave_pabili_session_row(
        $pdo,
        $buyerUserId,
        $riderUserId,
        $sessionId
    );
    if ($existingSession !== null && ($existingSession['pabili_status'] ?? '') === 'completed') {
        json_response([
            'success' => true,
            'data' => array_merge(
                [
                    'buyer_user_id' => $buyerUserId,
                    'rider_user_id' => $riderUserId,
                    'session_id' => $sessionId,
                    'done' => true,
                    'already_completed' => true,
                ],
                sukiwave_pabili_session_status_payload($existingSession),
            ),
        ]);
    }

    // Atomic upsert: first writer wins (sets completed_by/completed_at).
    // If another request raced ahead, ON DUPLICATE KEY only updates when
    // the row is still 'active', preserving the original completed_by.
    // `pabili_sessions.pabili_status` is the single source of truth for
    // completion — no more `__PABILI_DONE__` marker message in the chat.
    // Old marker rows from earlier deployments are still tolerated by
    // conversations-for-rider.php and the Dart `_isPabiliDoneRow` reader.
    $upsert = $pdo->prepare(
        "INSERT INTO pabili_sessions (
            buyer_user_id, rider_user_id, session_id,
            pabili_status, completed_at, completed_by, created_at
        ) VALUES (?, ?, ?, 'completed', NOW(), ?, NOW())
        ON DUPLICATE KEY UPDATE
            pabili_status = IF(pabili_status = 'completed', pabili_status, 'completed'),
            completed_at  = IF(pabili_status = 'completed', completed_at,  NOW()),
            completed_by  = IF(pabili_status = 'completed', completed_by,  VALUES(completed_by))"
    );
    $upsert->execute([
        $buyerUserId,
        $riderUserId,
        $sessionId,
        $completedBy,
    ]);

    $finalRow = sukiwave_pabili_session_row(
        $pdo,
        $buyerUserId,
        $riderUserId,
        $sessionId
    );

    json_response([
        'success' => true,
        'data' => array_merge(
            [
                'buyer_user_id' => $buyerUserId,
                'rider_user_id' => $riderUserId,
                'session_id' => $sessionId,
                'done' => true,
                'already_completed' => false,
            ],
            sukiwave_pabili_session_status_payload($finalRow),
        ),
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to mark Pabili as done.',
        'error' => $e->getMessage(),
    ], 400);
}
