<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';
require_once __DIR__ . '/../config/firebase_verify.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$idToken = trim((string)($input['id_token'] ?? ''));
$role = trim((string)($input['role'] ?? 'buyer'));

if ($idToken === '') {
    json_response(['success' => false, 'message' => 'id_token is required.'], 422);
}

if (!in_array($role, ['buyer', 'seller', 'rider'], true)) {
    json_response(['success' => false, 'message' => 'Role must be buyer, seller, or rider.'], 422);
}

try {
    $decoded = sukiwave_verify_firebase_id_token($idToken, sukiwave_firebase_project_id());
    $firebaseUid = trim((string)($decoded->sub ?? ''));
    $emailRaw = trim((string)($decoded->email ?? ''));
    $email = strtolower($emailRaw);
    $fullName = trim((string)($decoded->name ?? ''));

    if ($firebaseUid === '') {
        json_response(['success' => false, 'message' => 'Invalid Firebase token (missing subject).'], 401);
    }

    if ($email === '') {
        json_response([
            'success' => false,
            'message' => 'Facebook did not return an email. Grant the email permission or use another sign-in method.',
        ], 422);
    }

    $pdo = db();
    $hasFirebaseUidCol = sukiwave_users_has_firebase_uid_column($pdo);

    if (!$hasFirebaseUidCol) {
        json_response([
            'success' => false,
            'message' => 'Server is missing users.firebase_uid. Run api/sql/schema.v14.firebase_uid.mysql.sql.',
        ], 503);
    }

    $pdo->beginTransaction();

    $userId = null;

    $byUid = $pdo->prepare('SELECT id, role, email, firebase_uid FROM users WHERE firebase_uid = ? LIMIT 1');
    $byUid->execute([$firebaseUid]);
    $rowUid = $byUid->fetch();

    if ($rowUid) {
        $userId = (int)$rowUid['id'];
        $dbRole = (string)($rowUid['role'] ?? '');
        if ($dbRole !== $role) {
            $pdo->rollBack();
            json_response([
                'success' => false,
                'message' => 'This account is registered as a different role. Use the correct login.',
            ], 409);
        }
    } else {
        $byEmail = $pdo->prepare(
            'SELECT id, role, email, firebase_uid FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1'
        );
        $byEmail->execute([$email]);
        $rowEmail = $byEmail->fetch();

        if ($rowEmail) {
            $userId = (int)$rowEmail['id'];
            $dbRole = (string)($rowEmail['role'] ?? '');
            if ($dbRole !== $role) {
                $pdo->rollBack();
                json_response([
                    'success' => false,
                    'message' => 'An account with this email already exists with a different role.',
                ], 409);
            }
            $upd = $pdo->prepare('UPDATE users SET firebase_uid = ? WHERE id = ? AND firebase_uid IS NULL');
            $upd->execute([$firebaseUid, $userId]);
        }
    }

    if ($userId === null) {
        $initialName = $fullName !== ''
            ? $fullName
            : (strstr($email, '@', true) ?: $role);

        $randomSecret = bin2hex(random_bytes(32));
        $hash = password_hash($randomSecret, PASSWORD_DEFAULT);

        $ins = $pdo->prepare(
            'INSERT INTO users (role, full_name, email, phone, password_hash, is_active, firebase_uid)
             VALUES (:role, :full_name, :email, NULL, :password_hash, 1, :firebase_uid)'
        );
        $ins->execute([
            'role' => $role,
            'full_name' => $initialName,
            'email' => $emailRaw !== '' ? $emailRaw : $email,
            'password_hash' => $hash,
            'firebase_uid' => $firebaseUid,
        ]);
        $userId = (int)$pdo->lastInsertId();

        if ($role === 'seller') {
            $sn = $initialName;
            $sp = $pdo->prepare(
                'INSERT INTO seller_profiles (user_id, shop_name) VALUES (?, ?)'
            );
            $sp->execute([$userId, $sn]);
        } elseif ($role === 'rider') {
            $rp = $pdo->prepare(
                'INSERT INTO rider_profiles (user_id, vehicle_type, status) VALUES (?, ?, ?)'
            );
            $rp->execute([$userId, 'motorcycle', 'offline']);
        }
    }

    $pdo->commit();

    $user = sukiwave_fetch_user_login_row_by_id($pdo, $userId);
    if (!$user || (int)$user['is_active'] !== 1) {
        json_response(['success' => false, 'message' => 'Account is inactive or missing.'], 403);
    }

    unset($user['password_hash'], $user['is_active']);

    $data = $user;
    $userRole = (string)($user['role'] ?? '');
    if (in_array($userRole, ['buyer', 'seller', 'rider'], true)) {
        $data[$userRole . '_api_token'] = sukiwave_issue_role_token((int)$user['id'], $userRole);
    }

    json_response([
        'success' => true,
        'message' => 'Login successful.',
        'data' => $data,
    ]);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 401);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response([
        'success' => false,
        'message' => 'Federated login failed.',
        'error' => $e->getMessage(),
    ], 500);
}
