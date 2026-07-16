<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

/**
 * True when the query failed because table `pabili_sessions` is not deployed yet
 * (MySQL / MariaDB error 1146).
 */
function sukiwave_pabili_conversations_missing_sessions_table(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }
    $driverCode = (int) ($e->errorInfo[1] ?? 0);
    if ($driverCode !== 1146) {
        return false;
    }
    $msg = strtolower($e->getMessage());

    return str_contains($msg, 'pabili_sessions');
}

try {
    $pdo = db();
    $riderUserId = sukiwave_require_rider_from_bearer($pdo);
    $doneMarker = '__PABILI_DONE__';

    $limit = (int)($_GET['limit'] ?? 50);
    if ($limit <= 0) {
        $limit = 50;
    }
    if ($limit > 100) {
        $limit = 100;
    }

    $limitSql = ' LIMIT ' . (string) $limit;

    // Latest session per buyer; hide sessions already completed via either the
    // pabili_sessions row (new authoritative source) or the legacy in-band
    // marker message (back-compat with older clients before schema.v13).
    $sqlWithSessions = 'SELECT lm.buyer_user_id,
                   lm.session_id AS pabili_session_id,
                   lm.body AS last_message_body,
                   lm.sender_role AS last_sender_role,
                   lm.created_at AS last_message_at,
                   lm.image_url AS last_image_url,
                   lm.id AS last_message_id,
                   COALESCE(NULLIF(TRIM(u.full_name), \'\'), \'Buyer\') AS buyer_name,
                   COALESCE(TRIM(u.phone), \'\') AS buyer_phone
            FROM (
                SELECT buyer_user_id, MAX(session_id) AS max_sess
                FROM pabili_chat_messages
                WHERE rider_user_id = :rider_user_id_a
                GROUP BY buyer_user_id
            ) agg
            INNER JOIN (
                SELECT buyer_user_id, session_id, MAX(id) AS last_id
                FROM pabili_chat_messages
                WHERE rider_user_id = :rider_user_id_b
                GROUP BY buyer_user_id, session_id
            ) pick ON pick.buyer_user_id = agg.buyer_user_id
                  AND pick.session_id = agg.max_sess
            INNER JOIN pabili_chat_messages lm ON lm.id = pick.last_id
            INNER JOIN users u ON u.id = lm.buyer_user_id
            LEFT JOIN pabili_sessions ps
                ON ps.buyer_user_id = lm.buyer_user_id
               AND ps.rider_user_id = lm.rider_user_id
               AND ps.session_id    = lm.session_id
            WHERE COALESCE(TRIM(lm.body), \'\') <> :done_marker
              AND COALESCE(ps.pabili_status, \'active\') <> \'completed\'
            ORDER BY lm.id DESC' . $limitSql;

    // Same query without pabili_sessions (DBs that have not run schema.v13 yet).
    $sqlLegacy = 'SELECT lm.buyer_user_id,
                   lm.session_id AS pabili_session_id,
                   lm.body AS last_message_body,
                   lm.sender_role AS last_sender_role,
                   lm.created_at AS last_message_at,
                   lm.image_url AS last_image_url,
                   lm.id AS last_message_id,
                   COALESCE(NULLIF(TRIM(u.full_name), \'\'), \'Buyer\') AS buyer_name,
                   COALESCE(TRIM(u.phone), \'\') AS buyer_phone
            FROM (
                SELECT buyer_user_id, MAX(session_id) AS max_sess
                FROM pabili_chat_messages
                WHERE rider_user_id = :rider_user_id_a
                GROUP BY buyer_user_id
            ) agg
            INNER JOIN (
                SELECT buyer_user_id, session_id, MAX(id) AS last_id
                FROM pabili_chat_messages
                WHERE rider_user_id = :rider_user_id_b
                GROUP BY buyer_user_id, session_id
            ) pick ON pick.buyer_user_id = agg.buyer_user_id
                  AND pick.session_id = agg.max_sess
            INNER JOIN pabili_chat_messages lm ON lm.id = pick.last_id
            INNER JOIN users u ON u.id = lm.buyer_user_id
            WHERE COALESCE(TRIM(lm.body), \'\') <> :done_marker
            ORDER BY lm.id DESC' . $limitSql;

    $params = [
        'rider_user_id_a' => $riderUserId,
        'rider_user_id_b' => $riderUserId,
        'done_marker' => $doneMarker,
    ];

    try {
        $st = $pdo->prepare($sqlWithSessions);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];
    } catch (Throwable $queryEx) {
        if (!sukiwave_pabili_conversations_missing_sessions_table($queryEx)) {
            throw $queryEx;
        }
        $st = $pdo->prepare($sqlLegacy);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];
    }

    json_response([
        'success' => true,
        'data' => [
            'conversations' => $rows,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to load conversations.',
        'error' => $e->getMessage(),
    ], 400);
}
