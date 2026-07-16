<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$email = trim((string)($input['email'] ?? ''));
$phone = trim((string)($input['phone'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($email === '' && $phone === '') {
    json_response(['success' => false, 'message' => 'Email or phone is required.'], 422);
}

if ($password === '') {
    json_response(['success' => false, 'message' => 'Password is required.'], 422);
}

try {
    $pdo = db();
    $hasBuyerProfileImage = sukiwave_users_has_profile_image_column($pdo);
    $whereField = $email !== '' ? 'u.email' : 'u.phone';
    $identifier = $email !== '' ? $email : $phone;
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
         WHERE {$whereField} = :identifier
         LIMIT 1"
    );
    $stmt->execute(['identifier' => $identifier]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        json_response(['success' => false, 'message' => 'Invalid credentials.'], 401);
    }

    if (!password_verify($password, (string)$user['password_hash'])) {
        json_response(['success' => false, 'message' => 'Invalid credentials.'], 401);
    }

    unset($user['password_hash'], $user['is_active']);

    $data = $user;
    $role = (string)($user['role'] ?? '');
    if (in_array($role, ['buyer', 'seller', 'rider'], true)) {
        $data[$role . '_api_token'] = sukiwave_issue_role_token((int)$user['id'], $role);
    }
    if ($role === 'admin') {
        $data['admin_api_token'] = sukiwave_admin_issue_api_token($pdo, (int)$user['id']);
    }

    json_response([
        'success' => true,
        'message' => 'Login successful.',
        'data' => $data,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Login failed.',
        'error' => $e->getMessage(),
    ], 500);
}

