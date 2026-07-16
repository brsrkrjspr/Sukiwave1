<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

$pdo = db();
$hasProfileImage = sukiwave_users_has_profile_image_column($pdo);
$profileImageSelect = $hasProfileImage
    ? 'u.profile_image_url'
    : 'NULL AS profile_image_url';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $buyerUserId = sukiwave_require_buyer_from_bearer($pdo);
        $sql = "SELECT
                    u.id,
                    u.full_name,
                    u.email,
                    u.phone,
                    {$profileImageSelect}
                FROM users u
                WHERE u.id = ?
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$buyerUserId]);
        $row = $st->fetch();
        if (!$row) {
            json_response(['success' => false, 'message' => 'Buyer not found.'], 404);
        }
        json_response([
            'success' => true,
            'data' => [
                'id' => (int)$row['id'],
                'full_name' => (string)($row['full_name'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'phone' => $row['phone'] !== null ? (string)$row['phone'] : '',
                'profile_image_url' => $row['profile_image_url'] !== null
                    ? (string)$row['profile_image_url']
                    : null,
            ],
        ]);
    } catch (Throwable $e) {
        json_response([
            'success' => false,
            'message' => 'Failed to fetch buyer profile.',
            'error' => $e->getMessage(),
        ], 400);
    }
}

if ($method !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$fullName = trim((string)($input['full_name'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$phone = trim((string)($input['phone'] ?? ''));

if ($fullName === '' || $email === '') {
    json_response(['success' => false, 'message' => 'full_name and email are required.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['success' => false, 'message' => 'Invalid email format.'], 422);
}

try {
    $buyerUserId = sukiwave_require_buyer_from_bearer($pdo);

    $dup = $pdo->prepare(
        'SELECT id FROM users
         WHERE (email = :email OR (:phone <> "" AND phone = :phone))
           AND id <> :buyer_user_id
         LIMIT 1'
    );
    $dup->execute([
        'email' => $email,
        'phone' => $phone,
        'buyer_user_id' => $buyerUserId,
    ]);
    if ($dup->fetch()) {
        json_response([
            'success' => false,
            'message' => 'Email or phone is already used by another account.',
        ], 409);
    }

    $up = $pdo->prepare(
        'UPDATE users
         SET full_name = :full_name,
             email = :email,
             phone = :phone
         WHERE id = :buyer_user_id'
    );
    $up->execute([
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : null,
        'buyer_user_id' => $buyerUserId,
    ]);

    $sql = "SELECT
                u.id,
                u.full_name,
                u.email,
                u.phone,
                {$profileImageSelect}
            FROM users u
            WHERE u.id = ?
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$buyerUserId]);
    $row = $st->fetch();
    if (!$row) {
        json_response(['success' => false, 'message' => 'Buyer not found.'], 404);
    }

    json_response([
        'success' => true,
        'message' => 'Buyer profile updated.',
        'data' => [
            'id' => (int)$row['id'],
            'full_name' => (string)($row['full_name'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'phone' => $row['phone'] !== null ? (string)$row['phone'] : '',
            'profile_image_url' => $row['profile_image_url'] !== null
                ? (string)$row['profile_image_url']
                : null,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Failed to update buyer profile.',
        'error' => $e->getMessage(),
    ], 400);
}

