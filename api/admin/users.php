<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

function to_bool_flag(mixed $value, bool $fallback = false): bool
{
    if (is_bool($value)) return $value;
    if (is_int($value)) return $value === 1;
    if (is_string($value)) {
        $v = strtolower(trim($value));
        if ($v === '1' || $v === 'true' || $v === 'yes') return true;
        if ($v === '0' || $v === 'false' || $v === 'no') return false;
    }
    return $fallback;
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = read_json_body();
    $adminUserId = sukiwave_resolve_admin_identity($pdo, $input);

    if ($method === 'GET') {
        $role = trim((string)($_GET['role'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;
        $sortByRaw = strtolower(trim((string)($_GET['sort_by'] ?? 'created_at')));
        $sortDirRaw = strtolower(trim((string)($_GET['sort_dir'] ?? 'desc')));
        $sortDir = $sortDirRaw === 'asc' ? 'ASC' : 'DESC';
        $allowedSort = [
            'id' => 'u.id',
            'name' => 'u.full_name',
            'email' => 'u.email',
            'role' => 'u.role',
            'created_at' => 'u.created_at',
        ];
        $sortCol = $allowedSort[$sortByRaw] ?? $allowedSort['created_at'];

        $where = [];
        $params = [];
        if ($role !== '') {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }
        if ($q !== '') {
            $where[] = '(u.full_name LIKE :q OR u.email LIKE :q OR IFNULL(u.phone, \'\') LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = ' WHERE ' . implode(' AND ', $where);
        }

        $countSql = "SELECT COUNT(*) AS total FROM users u{$whereSql}";
        $countSt = $pdo->prepare($countSql);
        $countSt->execute($params);
        $total = (int)($countSt->fetchColumn() ?: 0);

        $sql = "SELECT
                    u.id,
                    u.role,
                    u.full_name,
                    u.email,
                    u.phone,
                    u.is_active,
                    u.created_at,
                    u.updated_at
                FROM users u{$whereSql}
                ORDER BY {$sortCol} {$sortDir}
                LIMIT :lim OFFSET :off";

        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue(':' . $k, $v);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        json_response([
            'success' => true,
            'data' => $st->fetchAll(),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ],
        ]);
    }

    if ($method !== 'POST') {
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $action = strtolower(trim((string)($input['action'] ?? '')));
    if ($action === 'create') {
        $role = strtolower(trim((string)($input['role'] ?? 'buyer')));
        $fullName = trim((string)($input['full_name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $isActive = to_bool_flag($input['is_active'] ?? true, true) ? 1 : 0;
        $shopName = trim((string)($input['shop_name'] ?? ''));

        if ($fullName === '' || $email === '' || $password === '') {
            json_response(['success' => false, 'message' => 'full_name, email, password are required.'], 422);
        }
        if (!in_array($role, ['buyer', 'seller', 'rider', 'admin'], true)) {
            json_response(['success' => false, 'message' => 'Invalid role.'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['success' => false, 'message' => 'Invalid email format.'], 422);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->beginTransaction();
        $st = $pdo->prepare(
            'INSERT INTO users (role, full_name, email, phone, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$role, $fullName, $email, ($phone !== '' ? $phone : null), $hash, $isActive]);
        $newUserId = (int)$pdo->lastInsertId();

        if ($role === 'seller') {
            $sp = $pdo->prepare('INSERT INTO seller_profiles (user_id, shop_name) VALUES (?, ?)');
            $sp->execute([$newUserId, $shopName !== '' ? $shopName : $fullName]);
        } elseif ($role === 'rider') {
            $rp = $pdo->prepare('INSERT INTO rider_profiles (user_id) VALUES (?)');
            $rp->execute([$newUserId]);
        }
        sukiwave_admin_audit($pdo, $adminUserId, 'create', 'user', $newUserId, [
            'role' => $role,
            'email' => $email,
            'full_name' => $fullName,
            'is_active' => $isActive,
        ]);
        $pdo->commit();
        json_response(['success' => true, 'message' => 'User created.', 'data' => ['id' => $newUserId]], 201);
    }

    if ($action === 'update') {
        $userId = (int)($input['user_id'] ?? 0);
        if ($userId <= 0) {
            json_response(['success' => false, 'message' => 'user_id is required.'], 422);
        }
        $role = strtolower(trim((string)($input['role'] ?? '')));
        $fullName = trim((string)($input['full_name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $isActive = to_bool_flag($input['is_active'] ?? true, true) ? 1 : 0;
        $password = (string)($input['password'] ?? '');

        if ($role !== '' && !in_array($role, ['buyer', 'seller', 'rider', 'admin'], true)) {
            json_response(['success' => false, 'message' => 'Invalid role.'], 422);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['success' => false, 'message' => 'Invalid email format.'], 422);
        }

        $parts = ['is_active = :is_active'];
        $params = ['is_active' => $isActive, 'id' => $userId];
        if ($role !== '') {
            $parts[] = 'role = :role';
            $params['role'] = $role;
        }
        if ($fullName !== '') {
            $parts[] = 'full_name = :full_name';
            $params['full_name'] = $fullName;
        }
        if ($email !== '') {
            $parts[] = 'email = :email';
            $params['email'] = $email;
        }
        $parts[] = 'phone = :phone';
        $params['phone'] = $phone !== '' ? $phone : null;
        if ($password !== '') {
            $parts[] = 'password_hash = :password_hash';
            $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $sql = 'UPDATE users SET ' . implode(', ', $parts) . ' WHERE id = :id';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        sukiwave_admin_audit($pdo, $adminUserId, 'update', 'user', $userId, [
            'role' => $role,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'is_active' => $isActive,
            'password_changed' => $password !== '',
        ]);
        json_response(['success' => true, 'message' => 'User updated.']);
    }

    if ($action === 'delete') {
        $userId = (int)($input['user_id'] ?? 0);
        if ($userId <= 0) {
            json_response(['success' => false, 'message' => 'user_id is required.'], 422);
        }
        if ($userId === $adminUserId) {
            json_response(['success' => false, 'message' => 'You cannot delete your own admin account.'], 422);
        }
        $st = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $st->execute([$userId]);
        if ($st->rowCount() === 0) {
            json_response(['success' => false, 'message' => 'User not found.'], 404);
        }
        sukiwave_admin_audit($pdo, $adminUserId, 'delete', 'user', $userId, null);
        json_response(['success' => true, 'message' => 'User deleted.']);
    }

    json_response(['success' => false, 'message' => 'Unsupported action.'], 422);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Admin users request failed.',
        'error' => $e->getMessage(),
    ], 400);
}
