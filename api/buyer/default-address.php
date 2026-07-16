<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$pdo = db();

// Authenticate first. Token errors / role mismatch are 401, not 500.
$buyerUserId = 0;
try {
    $buyerUserId = sukiwave_require_buyer_from_bearer($pdo);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Please sign in again to load your delivery address.',
        'error' => $e->getMessage(),
    ], 401);
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            id,
            buyer_user_id,
            label,
            contact_name,
            contact_phone,
            address_line1,
            address_line2,
            barangay,
            city,
            province,
            postal_code,
            is_default
         FROM buyer_addresses
         WHERE buyer_user_id = :buyer_user_id
         ORDER BY is_default DESC, id ASC
         LIMIT 1"
    );
    $stmt->execute(['buyer_user_id' => $buyerUserId]);
    $row = $stmt->fetch();

    // No saved address yet is a normal state, not an error: respond 200 with null data
    // so clients can render an "Add address" empty state without parsing error codes.
    if (!$row) {
        json_response([
            'success' => true,
            'data' => null,
            'message' => 'No address found for this buyer.',
        ]);
    }

    json_response([
        'success' => true,
        'data' => [
            'id' => (int)$row['id'],
            'buyer_user_id' => (int)$row['buyer_user_id'],
            'label' => (string)($row['label'] ?? ''),
            'contact_name' => (string)($row['contact_name'] ?? ''),
            'contact_phone' => (string)($row['contact_phone'] ?? ''),
            'address_line1' => (string)($row['address_line1'] ?? ''),
            'address_line2' => $row['address_line2'] !== null ? (string)$row['address_line2'] : null,
            'barangay' => $row['barangay'] !== null ? (string)$row['barangay'] : null,
            'city' => (string)($row['city'] ?? ''),
            'province' => $row['province'] !== null ? (string)$row['province'] : null,
            'postal_code' => $row['postal_code'] !== null ? (string)$row['postal_code'] : null,
            'is_default' => (int)($row['is_default'] ?? 0),
        ],
    ]);
} catch (Throwable $e) {
    error_log('buyer/default-address: ' . $e->getMessage());
    json_response([
        'success' => false,
        'message' => 'Failed to load your delivery address.',
        'error' => $e->getMessage(),
    ], 500);
}
