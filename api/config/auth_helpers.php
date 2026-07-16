<?php
declare(strict_types=1);

function sukiwave_public_base_url(): string
{
    $proto = 'https';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']);
    } elseif (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        $proto = 'http';
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $proto . '://' . $host;
}

/**
 * Public URL root for this API (e.g. http://localhost/sukiwave/api).
 * Uploads live under {api_root}/uploads/...
 */
function sukiwave_public_api_root_url(): string
{
    $proto = 'https';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']);
    } elseif (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        $proto = 'http';
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') {
        return $proto . '://' . $host;
    }
    $dir = dirname($script);
    while ($dir !== '/' && $dir !== '.' && $dir !== '') {
        if (basename($dir) === 'api') {
            return $proto . '://' . $host . $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return $proto . '://' . $host;
}

function sukiwave_require_seller(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        throw new RuntimeException('Invalid seller user id.');
    }
    $st = $pdo->prepare('SELECT id, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row || (string)($row['role'] ?? '') !== 'seller') {
        throw new RuntimeException('Not a seller account.');
    }
}

function sukiwave_require_buyer(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        throw new RuntimeException('Invalid buyer user id.');
    }
    $st = $pdo->prepare('SELECT id, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row || (string)($row['role'] ?? '') !== 'buyer') {
        throw new RuntimeException('Not a buyer account.');
    }
}

function sukiwave_require_admin(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        throw new RuntimeException('Invalid admin user id.');
    }
    $st = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Admin account not found.');
    }
    if ((string)($row['role'] ?? '') !== 'admin') {
        throw new RuntimeException('Not an admin account.');
    }
    if ((int)($row['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('Admin account is inactive.');
    }
}

function sukiwave_admin_issue_api_token(PDO $pdo, int $adminUserId): string
{
    $token = bin2hex(random_bytes(24));
    $hash = hash('sha256', $token);
    $st = $pdo->prepare(
        'INSERT INTO admin_api_tokens (admin_user_id, token_hash, expires_at, created_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())'
    );
    $st->execute([$adminUserId, $hash]);
    return $token;
}

function sukiwave_extract_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return '';
    }
    return trim((string)$m[1]);
}

function sukiwave_token_secret(): string
{
    $raw = getenv('SUKIWAVE_TOKEN_SECRET');
    $secret = is_string($raw) ? trim($raw) : '';
    if ($secret !== '') {
        return $secret;
    }
    return 'sukiwave-dev-insecure-secret-change-me';
}

function sukiwave_base64url_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function sukiwave_base64url_decode(string $encoded): string|false
{
    $remainder = strlen($encoded) % 4;
    if ($remainder > 0) {
        $encoded .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($encoded, '-_', '+/'), true);
}

function sukiwave_issue_role_token(int $userId, string $role, int $ttlSeconds = 604800): string
{
    $now = time();
    $payload = [
        'uid' => $userId,
        'role' => $role,
        'iat' => $now,
        'exp' => $now + max(60, $ttlSeconds),
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        throw new RuntimeException('Failed to encode auth token payload.');
    }
    $payloadEncoded = sukiwave_base64url_encode($payloadJson);
    $sig = hash_hmac('sha256', $payloadEncoded, sukiwave_token_secret(), true);
    return $payloadEncoded . '.' . sukiwave_base64url_encode($sig);
}

/**
 * Verify HMAC signature and expiry; returns uid + role from payload.
 *
 * @return array{uid: int, role: string, exp: int}
 */
function sukiwave_verify_signed_role_token_payload(string $token): array
{
    if ($token === '' || !str_contains($token, '.')) {
        throw new RuntimeException('Missing auth token.');
    }
    [$payloadEncoded, $sigEncoded] = explode('.', $token, 2);
    $sigRaw = sukiwave_base64url_decode($sigEncoded);
    if ($sigRaw === false) {
        throw new RuntimeException('Invalid auth token signature.');
    }
    $expectedSig = hash_hmac('sha256', $payloadEncoded, sukiwave_token_secret(), true);
    if (!hash_equals($expectedSig, $sigRaw)) {
        throw new RuntimeException('Invalid auth token signature.');
    }
    $payloadRaw = sukiwave_base64url_decode($payloadEncoded);
    if ($payloadRaw === false) {
        throw new RuntimeException('Invalid auth token payload.');
    }
    $payload = json_decode($payloadRaw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid auth token payload.');
    }
    $uid = (int)($payload['uid'] ?? 0);
    $role = (string)($payload['role'] ?? '');
    $exp = (int)($payload['exp'] ?? 0);
    if ($uid <= 0 || $exp <= time()) {
        throw new RuntimeException('Auth token is invalid or expired.');
    }
    return ['uid' => $uid, 'role' => $role, 'exp' => $exp];
}

function sukiwave_verify_role_token(string $token, string $expectedRole): int
{
    $payload = sukiwave_verify_signed_role_token_payload($token);
    if ($payload['role'] !== $expectedRole) {
        throw new RuntimeException('Auth token is invalid or expired.');
    }
    return $payload['uid'];
}

function sukiwave_pabili_stable_conversation_id(int $buyerUserId, int $riderUserId): int
{
    return ($buyerUserId * 1000003) ^ ($riderUserId * 9176);
}

/**
 * Fallback session id when no rows exist yet (rider opens chat before buyer sends).
 * Stays below normal client session ids (~microsecond timestamps). Must match Dart.
 */
function sukiwave_pabili_bootstrap_session_id(int $buyerUserId, int $riderUserId): int
{
    $xor = ($buyerUserId * 1000003) ^ ($riderUserId * 9176);
    $u = abs($xor) % 2147483647;
    if ($u === 0) {
        $u = 1;
    }
    return $u;
}

/**
 * Read the lifecycle row for a Pabili (buyer, rider, session) tuple.
 * Returns null when no row exists yet ⇒ treat as 'active'.
 *
 * @return array{pabili_status: string, completed_at: ?string, completed_by: ?string}|null
 */
function sukiwave_pabili_session_row(
    PDO $pdo,
    int $buyerUserId,
    int $riderUserId,
    int $sessionId
): ?array {
    if ($buyerUserId <= 0 || $riderUserId <= 0 || $sessionId <= 0) {
        return null;
    }
    // Ensure the v13 table exists before reading; older deployments may not
    // have run that migration. Cheap (static-cached) after first call.
    sukiwave_ensure_pabili_sessions_table($pdo);
    $st = $pdo->prepare(
        'SELECT pabili_status, completed_at, completed_by
         FROM pabili_sessions
         WHERE buyer_user_id = ? AND rider_user_id = ? AND session_id = ?
         LIMIT 1'
    );
    $st->execute([$buyerUserId, $riderUserId, $sessionId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    return [
        'pabili_status' => (string)($row['pabili_status'] ?? 'active'),
        'completed_at' => $row['completed_at'] === null ? null : (string)$row['completed_at'],
        'completed_by' => $row['completed_by'] === null ? null : (string)$row['completed_by'],
    ];
}

/**
 * Normalize a session row (or absence) into an API-shaped status object.
 *
 * @return array{pabili_status: string, completed_at: ?string, completed_by: ?string, is_completed: bool}
 */
function sukiwave_pabili_session_status_payload(?array $row): array
{
    $status = $row['pabili_status'] ?? 'active';
    if ($status !== 'completed') {
        $status = 'active';
    }
    return [
        'pabili_status' => $status,
        'completed_at' => $row['completed_at'] ?? null,
        'completed_by' => $row['completed_by'] ?? null,
        'is_completed' => $status === 'completed',
    ];
}

/**
 * Caller must be the buyer or rider in this Pabili pair (Bearer token).
 *
 * @return array{sender_role: string, sender_user_id: int}
 */
function sukiwave_require_pabili_pair_auth(PDO $pdo, int $buyerUserId, int $riderUserId): array
{
    if ($buyerUserId <= 0 || $riderUserId <= 0) {
        throw new RuntimeException('Invalid conversation participants.');
    }
    $payload = sukiwave_verify_signed_role_token_payload(sukiwave_extract_bearer_token());
    $uid = $payload['uid'];
    $role = $payload['role'];
    if ($role === 'buyer') {
        sukiwave_require_buyer($pdo, $uid);
        if ($uid !== $buyerUserId) {
            throw new RuntimeException('You are not a participant in this conversation.');
        }
        return ['sender_role' => 'buyer', 'sender_user_id' => $uid];
    }
    if ($role === 'rider') {
        $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'rider' AND is_active = 1 LIMIT 1");
        $st->execute([$uid]);
        if (!$st->fetch()) {
            throw new RuntimeException('Rider account not found or inactive.');
        }
        if ($uid !== $riderUserId) {
            throw new RuntimeException('You are not a participant in this conversation.');
        }
        return ['sender_role' => 'rider', 'sender_user_id' => $uid];
    }
    throw new RuntimeException('Only buyer or rider tokens can access Pabili chat.');
}

function sukiwave_require_seller_from_bearer(PDO $pdo): int
{
    $userId = sukiwave_verify_role_token(sukiwave_extract_bearer_token(), 'seller');
    sukiwave_require_seller($pdo, $userId);
    return $userId;
}

function sukiwave_require_buyer_from_bearer(PDO $pdo): int
{
    $userId = sukiwave_verify_role_token(sukiwave_extract_bearer_token(), 'buyer');
    sukiwave_require_buyer($pdo, $userId);
    return $userId;
}

function sukiwave_require_rider_from_bearer(PDO $pdo): int
{
    $userId = sukiwave_verify_role_token(sukiwave_extract_bearer_token(), 'rider');
    $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'rider' AND is_active = 1 LIMIT 1");
    $st->execute([$userId]);
    if (!$st->fetch()) {
        throw new RuntimeException('Rider account not found or inactive.');
    }
    return $userId;
}

function sukiwave_require_admin_from_bearer(PDO $pdo): int
{
    $token = sukiwave_extract_bearer_token();
    if ($token === '') {
        throw new RuntimeException('Missing admin token.');
    }
    $hash = hash('sha256', $token);
    $st = $pdo->prepare(
        "SELECT t.admin_user_id
         FROM admin_api_tokens t
         INNER JOIN users u ON u.id = t.admin_user_id
         WHERE t.token_hash = ?
           AND t.revoked_at IS NULL
           AND t.expires_at > NOW()
           AND u.role = 'admin'
           AND u.is_active = 1
         LIMIT 1"
    );
    $st->execute([$hash]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Invalid or expired admin token.');
    }
    return (int)($row['admin_user_id'] ?? 0);
}

function sukiwave_resolve_admin_identity(PDO $pdo, array $input = []): int
{
    $token = sukiwave_extract_bearer_token();
    if ($token !== '') {
        return sukiwave_require_admin_from_bearer($pdo);
    }
    $adminUserId = (int)($_GET['admin_user_id'] ?? $input['admin_user_id'] ?? 0);
    if ($adminUserId > 0) {
        sukiwave_require_admin($pdo, $adminUserId);
        return $adminUserId;
    }

    $adminEmail = trim((string)($_GET['admin_email'] ?? $input['admin_email'] ?? ''));
    if ($adminEmail !== '') {
        $st = $pdo->prepare(
            "SELECT id
             FROM users
             WHERE email = ?
               AND role = 'admin'
               AND is_active = 1
             LIMIT 1"
        );
        $st->execute([$adminEmail]);
        $row = $st->fetch();
        if ($row) {
            return (int)($row['id'] ?? 0);
        }
    }

    throw new RuntimeException('Invalid admin user id.');
}

function sukiwave_admin_audit(
    PDO $pdo,
    int $adminUserId,
    string $actionType,
    string $targetType,
    ?int $targetId = null,
    ?array $payload = null
): void {
    $payloadJson = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);
    $st = $pdo->prepare(
        'INSERT INTO admin_actions (admin_user_id, action_type, target_type, target_id, payload_json, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $st->execute([$adminUserId, $actionType, $targetType, $targetId, $payloadJson]);
}

function sukiwave_category_id_for_seller_type(PDO $pdo, ?string $sellerType): ?int
{
    if ($sellerType === null || $sellerType === '') {
        return null;
    }
    $name = match ($sellerType) {
        'Grocery/General Merchandise' => 'Grocery',
        'Food Store/ Restaurant' => 'Food/Drinks',
        'Farm to Market' => 'Farming',
        'Sole seller' => null,
        default => null,
    };
    if ($name === null) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
    $st->execute([$name]);
    $row = $st->fetch();
    if ($row) {
        return (int)$row['id'];
    }
    $st = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
    $st->execute([$name]);
    return (int)$pdo->lastInsertId();
}

function sukiwave_category_id_for_name(PDO $pdo, ?string $categoryName): ?int
{
    if ($categoryName === null) {
        return null;
    }
    $name = trim($categoryName);
    if ($name === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
    $st->execute([$name]);
    $row = $st->fetch();
    if ($row) {
        return (int)$row['id'];
    }
    $st = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
    $st->execute([$name]);
    return (int)$pdo->lastInsertId();
}

/**
 * Same join shape as auth/login.php for JSON responses (includes password_hash — caller strips).
 *
 * @return array<string, mixed>|false
 */
function sukiwave_fetch_user_login_row_by_id(PDO $pdo, int $userId)
{
    if ($userId <= 0) {
        return false;
    }
    $hasBuyerProfileImage = sukiwave_users_has_profile_image_column($pdo);
    $buyerProfileImageSelect = $hasBuyerProfileImage
        ? 'u.profile_image_url AS profile_image_url,'
        : 'NULL AS profile_image_url,';

    $stmt = $pdo->prepare(
        "SELECT u.id, u.role, u.full_name, u.email, u.phone, {$buyerProfileImageSelect} u.password_hash, u.is_active,
                sp.shop_name AS shop_name,
                sp.store_description AS store_description,
                sp.operating_days_json AS operating_days_json,
                sp.store_image_url AS store_image_url,
                sp.store_address_line1 AS store_address_line1,
                sp.store_barangay AS store_barangay,
                sp.store_city AS store_city,
                sp.store_latitude AS store_latitude,
                sp.store_longitude AS store_longitude
         FROM users u
         LEFT JOIN seller_profiles sp ON sp.user_id = u.id
         WHERE u.id = :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    return $user ?: false;
}
