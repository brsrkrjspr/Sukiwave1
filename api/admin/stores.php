<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = read_json_body();
    $adminUserId = sukiwave_resolve_admin_identity($pdo, $input);

    if ($method === 'GET') {
        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;
        $sortByRaw = strtolower(trim((string)($_GET['sort_by'] ?? 'updated_at')));
        $sortDirRaw = strtolower(trim((string)($_GET['sort_dir'] ?? 'desc')));
        $sortDir = $sortDirRaw === 'asc' ? 'ASC' : 'DESC';
        $allowedSort = [
            'shop_name' => 'shop_name',
            'owner_name' => 'owner_name',
            'email' => 'u.email',
            'city' => 'sp.store_city',
            'updated_at' => 'sp.updated_at',
        ];
        $sortCol = $allowedSort[$sortByRaw] ?? $allowedSort['updated_at'];
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(sp.shop_name LIKE :q OR u.full_name LIKE :q OR u.email LIKE :q OR IFNULL(sp.store_city, \'\') LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $whereSql = '';
        if (!empty($where)) {
            $whereSql = ' AND ' . implode(' AND ', $where);
        }

        $countSql = "SELECT COUNT(*)
                     FROM seller_profiles sp
                     INNER JOIN users u ON u.id = sp.user_id
                     WHERE u.role = 'seller'{$whereSql}";
        $countSt = $pdo->prepare($countSql);
        $countSt->execute($params);
        $total = (int)($countSt->fetchColumn() ?: 0);

        $sql = "SELECT
                    sp.id,
                    sp.user_id AS seller_user_id,
                    COALESCE(sp.shop_name, u.full_name, 'Seller') AS shop_name,
                    u.full_name AS owner_name,
                    u.email,
                    u.phone,
                    u.is_active,
                    sp.store_city,
                    sp.store_barangay,
                    sp.store_address_line1,
                    sp.store_image_url,
                    sp.updated_at
                FROM seller_profiles sp
                INNER JOIN users u ON u.id = sp.user_id
                WHERE u.role = 'seller'{$whereSql}
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
    if ($action === 'update') {
        $sellerUserId = (int)($input['seller_user_id'] ?? 0);
        if ($sellerUserId <= 0) {
            json_response(['success' => false, 'message' => 'seller_user_id is required.'], 422);
        }
        $shopName = trim((string)($input['shop_name'] ?? ''));
        $street = trim((string)($input['store_address_line1'] ?? ''));
        $barangay = trim((string)($input['store_barangay'] ?? ''));
        $city = trim((string)($input['store_city'] ?? ''));
        $isActive = isset($input['is_active']) ? ((int)$input['is_active'] === 1 ? 1 : 0) : null;

        $sp = $pdo->prepare(
            'UPDATE seller_profiles
             SET shop_name = :shop_name,
                 store_address_line1 = :street,
                 store_barangay = :barangay,
                 store_city = :city
             WHERE user_id = :uid'
        );
        $sp->execute([
            'shop_name' => $shopName !== '' ? $shopName : null,
            'street' => $street !== '' ? $street : null,
            'barangay' => $barangay !== '' ? $barangay : null,
            'city' => $city !== '' ? $city : null,
            'uid' => $sellerUserId,
        ]);

        if ($isActive !== null) {
            $u = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ? AND role = \'seller\'');
            $u->execute([$isActive, $sellerUserId]);
        }
        sukiwave_admin_audit($pdo, $adminUserId, 'update', 'store', $sellerUserId, [
            'shop_name' => $shopName,
            'store_address_line1' => $street,
            'store_barangay' => $barangay,
            'store_city' => $city,
            'is_active' => $isActive,
        ]);

        json_response(['success' => true, 'message' => 'Store updated.']);
    }

    if ($action === 'delete') {
        $sellerUserId = (int)($input['seller_user_id'] ?? 0);
        if ($sellerUserId <= 0) {
            json_response(['success' => false, 'message' => 'seller_user_id is required.'], 422);
        }
        $st = $pdo->prepare('DELETE FROM users WHERE id = ? AND role = \'seller\'');
        $st->execute([$sellerUserId]);
        if ($st->rowCount() === 0) {
            json_response(['success' => false, 'message' => 'Store not found.'], 404);
        }
        sukiwave_admin_audit($pdo, $adminUserId, 'delete', 'store', $sellerUserId, null);
        json_response(['success' => true, 'message' => 'Store deleted.']);
    }

    json_response(['success' => false, 'message' => 'Unsupported action.'], 422);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Admin stores request failed.',
        'error' => $e->getMessage(),
    ], 400);
}
