<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

function admin_table_exists(PDO $pdo, string $table): bool
{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($db === false || $db === null || (string)$db === '') {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $st->execute([(string)$db, $table]);
    return (int)$st->fetchColumn() > 0;
}

function admin_require_flash_deals_table(PDO $pdo): void
{
    if (admin_table_exists($pdo, 'homepage_flash_deals')) {
        return;
    }
    throw new RuntimeException(
        'homepage_flash_deals table is missing. Run api/sql/schema.mysql.sql (or add that table migration) on your current database.'
    );
}

function admin_product_exists(PDO $pdo, int $productId): bool
{
    if ($productId <= 0) return false;
    $st = $pdo->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
    $st->execute([$productId]);
    return (bool)$st->fetch();
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = read_json_body();
    $adminUserId = sukiwave_resolve_admin_identity($pdo, $input);
    admin_require_flash_deals_table($pdo);

    if ($method === 'GET') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;
        $sortByRaw = strtolower(trim((string)($_GET['sort_by'] ?? 'updated_at')));
        $sortDirRaw = strtolower(trim((string)($_GET['sort_dir'] ?? 'desc')));
        $sortDir = $sortDirRaw === 'asc' ? 'ASC' : 'DESC';
        $allowedSort = [
            'id' => 'd.id',
            'product_id' => 'd.product_id',
            'discount_percent' => 'd.discount_percent',
            'updated_at' => 'd.updated_at',
            'ends_at' => 'd.ends_at',
        ];
        $sortCol = $allowedSort[$sortByRaw] ?? $allowedSort['updated_at'];

        $countSt = $pdo->query('SELECT COUNT(*) FROM homepage_flash_deals');
        $total = (int)($countSt->fetchColumn() ?: 0);

        $sql = "SELECT
                    d.id,
                    d.product_id,
                    d.title,
                    d.badge_text,
                    d.discount_percent,
                    d.starts_at,
                    d.ends_at,
                    d.is_active,
                    p.name AS product_name,
                    p.price AS base_price,
                    COALESCE(c.name, 'Uncategorized') AS category,
                    COALESCE(sp.shop_name, u.full_name, 'Seller') AS seller_name
                FROM homepage_flash_deals d
                INNER JOIN products p ON p.id = d.product_id
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN users u ON u.id = p.seller_user_id
                LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_user_id
                ORDER BY {$sortCol} {$sortDir}
                LIMIT :lim OFFSET :off";
        $st = $pdo->prepare($sql);
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
        $productId = (int)($input['product_id'] ?? 0);
        $title = trim((string)($input['title'] ?? 'Limited deal'));
        $badgeText = trim((string)($input['badge_text'] ?? ''));
        $discountPercent = (float)($input['discount_percent'] ?? 0);
        $startsAt = trim((string)($input['starts_at'] ?? ''));
        $endsAt = trim((string)($input['ends_at'] ?? ''));
        $isActive = (int)($input['is_active'] ?? 1) === 1 ? 1 : 0;

        if ($productId <= 0 || $discountPercent <= 0) {
            json_response(['success' => false, 'message' => 'product_id and discount_percent are required.'], 422);
        }
        if (!admin_product_exists($pdo, $productId)) {
            json_response(['success' => false, 'message' => 'Selected product does not exist.'], 422);
        }
        if ($discountPercent > 95) {
            json_response(['success' => false, 'message' => 'discount_percent must be <= 95.'], 422);
        }
        if ($title === '') $title = 'Limited deal';

        $st = $pdo->prepare(
            'INSERT INTO homepage_flash_deals (product_id, title, badge_text, discount_percent, starts_at, ends_at, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $productId,
            $title,
            ($badgeText !== '' ? $badgeText : null),
            $discountPercent,
            ($startsAt !== '' ? $startsAt : null),
            ($endsAt !== '' ? $endsAt : null),
            $isActive,
        ]);
        $newId = (int)$pdo->lastInsertId();
        sukiwave_admin_audit($pdo, $adminUserId, 'create', 'flash_deal', $newId, [
            'product_id' => $productId,
            'discount_percent' => $discountPercent,
            'title' => $title,
            'is_active' => $isActive,
        ]);
        json_response(['success' => true, 'message' => 'Flash deal created.', 'data' => ['id' => $newId]], 201);
    }

    if ($action === 'update') {
        $dealId = (int)($input['deal_id'] ?? 0);
        if ($dealId <= 0) {
            json_response(['success' => false, 'message' => 'deal_id is required.'], 422);
        }
        $productId = (int)($input['product_id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        $badgeText = trim((string)($input['badge_text'] ?? ''));
        $discountPercent = (float)($input['discount_percent'] ?? 0);
        $startsAt = trim((string)($input['starts_at'] ?? ''));
        $endsAt = trim((string)($input['ends_at'] ?? ''));
        $isActive = (int)($input['is_active'] ?? 1) === 1 ? 1 : 0;

        if ($productId <= 0 || $discountPercent <= 0 || $discountPercent > 95) {
            json_response(['success' => false, 'message' => 'Invalid product_id or discount_percent.'], 422);
        }
        if (!admin_product_exists($pdo, $productId)) {
            json_response(['success' => false, 'message' => 'Selected product does not exist.'], 422);
        }

        $st = $pdo->prepare(
            'UPDATE homepage_flash_deals
             SET product_id = ?, title = ?, badge_text = ?, discount_percent = ?, starts_at = ?, ends_at = ?, is_active = ?
             WHERE id = ?'
        );
        $st->execute([
            $productId,
            ($title !== '' ? $title : 'Limited deal'),
            ($badgeText !== '' ? $badgeText : null),
            $discountPercent,
            ($startsAt !== '' ? $startsAt : null),
            ($endsAt !== '' ? $endsAt : null),
            $isActive,
            $dealId,
        ]);
        sukiwave_admin_audit($pdo, $adminUserId, 'update', 'flash_deal', $dealId, [
            'product_id' => $productId,
            'discount_percent' => $discountPercent,
            'title' => $title,
            'is_active' => $isActive,
        ]);
        json_response(['success' => true, 'message' => 'Flash deal updated.']);
    }

    if ($action === 'delete') {
        $dealId = (int)($input['deal_id'] ?? 0);
        if ($dealId <= 0) {
            json_response(['success' => false, 'message' => 'deal_id is required.'], 422);
        }
        $st = $pdo->prepare('DELETE FROM homepage_flash_deals WHERE id = ?');
        $st->execute([$dealId]);
        if ($st->rowCount() === 0) {
            json_response(['success' => false, 'message' => 'Flash deal not found.'], 404);
        }
        sukiwave_admin_audit($pdo, $adminUserId, 'delete', 'flash_deal', $dealId, null);
        json_response(['success' => true, 'message' => 'Flash deal deleted.']);
    }

    json_response(['success' => false, 'message' => 'Unsupported action.'], 422);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Admin flash deals request failed.',
        'error' => $e->getMessage(),
    ], 400);
}
