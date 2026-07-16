<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$email = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');
$fullName = trim((string)($input['full_name'] ?? ''));
$role = trim((string)($input['role'] ?? 'buyer'));
$phone = trim((string)($input['phone'] ?? ''));
$shopName = trim((string)($input['shop_name'] ?? ''));

if ($email === '' || $password === '' || $fullName === '') {
    json_response(['success' => false, 'message' => 'Email, password, and full name are required.'], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['success' => false, 'message' => 'Invalid email format.'], 422);
}

if (!in_array($role, ['buyer', 'seller', 'rider'], true)) {
    json_response(['success' => false, 'message' => 'Role must be buyer, seller, or rider.'], 422);
}

if (strlen($password) < 6) {
    json_response(['success' => false, 'message' => 'Password must be at least 6 characters.'], 422);
}

$pdo = db();

try {
    $check = $pdo->prepare('SELECT id, email, phone FROM users WHERE email = :email OR (:phone <> "" AND phone = :phone) LIMIT 1');
    $check->execute([
        'email' => $email,
        'phone' => $phone,
    ]);
    $existing = $check->fetch();
    if ($existing) {
        $existingEmail = trim((string)($existing['email'] ?? ''));
        $existingPhone = trim((string)($existing['phone'] ?? ''));
        if ($phone !== '' && $existingPhone !== '' && strcasecmp($existingPhone, $phone) === 0) {
            json_response(['success' => false, 'message' => 'Phone number already registered.'], 409);
        }
        if ($existingEmail !== '' && strcasecmp($existingEmail, $email) === 0) {
            json_response(['success' => false, 'message' => 'Email already registered.'], 409);
        }
        json_response(['success' => false, 'message' => 'Account already exists.'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO users (role, full_name, email, phone, password_hash, is_active)
         VALUES (:role, :full_name, :email, :phone, :password_hash, 1)'
    );
    $stmt->execute([
        'role' => $role,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : null,
        'password_hash' => $hash,
    ]);
    $userId = (int)$pdo->lastInsertId();

    if ($role === 'seller') {
        $sn = $shopName !== '' ? $shopName : $fullName;
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

    $pdo->commit();

    $shopOut = null;
    if ($role === 'seller') {
        $shopOut = $shopName !== '' ? $shopName : $fullName;
    }
    $roleToken = null;
    if (in_array($role, ['buyer', 'seller', 'rider'], true)) {
        $roleToken = sukiwave_issue_role_token($userId, $role);
    }

    json_response([
        'success' => true,
        'message' => 'Account created.',
        'data' => [
            'id' => $userId,
            'role' => $role,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'shop_name' => $shopOut,
            'api_token' => $roleToken,
            $role . '_api_token' => $roleToken,
        ],
    ], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response([
        'success' => false,
        'message' => 'Registration failed.',
        'error' => $e->getMessage(),
    ], 400);
}
