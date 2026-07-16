<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$pdo = db();

$buyerUserId = 0;
try {
    $buyerUserId = sukiwave_require_buyer_from_bearer($pdo);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Please sign in again to save your delivery address.',
        'error' => $e->getMessage(),
    ], 401);
}

$input = read_json_body();

$nullable = static function ($value): ?string {
    if ($value === null) return null;
    $s = trim((string)$value);
    return $s === '' ? null : $s;
};

$label = $nullable($input['label'] ?? 'Home') ?? 'Home';
$contactName = trim((string)($input['contact_name'] ?? $input['recipient'] ?? ''));
$contactPhone = trim((string)($input['contact_phone'] ?? $input['phone'] ?? ''));
$addressLine1 = trim((string)($input['address_line1'] ?? $input['street'] ?? ''));
$addressLine2 = $nullable($input['address_line2'] ?? null);
$barangay = $nullable($input['barangay'] ?? null);
$city = trim((string)($input['city'] ?? ''));
$province = $nullable($input['province'] ?? null);
$postalCode = $nullable($input['postal_code'] ?? null);
$notes = $nullable($input['notes'] ?? null);
$setDefault = isset($input['set_default']) ? (bool)$input['set_default'] : true;
$addressId = isset($input['address_id']) ? (int)$input['address_id'] : 0;

$latRaw = $input['latitude'] ?? null;
$lngRaw = $input['longitude'] ?? null;
$latitude = ($latRaw === null || $latRaw === '') ? null : (float)$latRaw;
$longitude = ($lngRaw === null || $lngRaw === '') ? null : (float)$lngRaw;

if ($contactName === '') {
    json_response(['success' => false, 'message' => 'Recipient name is required.'], 422);
}
if ($contactPhone === '') {
    json_response(['success' => false, 'message' => 'Contact phone is required.'], 422);
}
if ($addressLine1 === '') {
    json_response(['success' => false, 'message' => 'Street / address is required.'], 422);
}
if ($city === '') {
    json_response(['success' => false, 'message' => 'City is required.'], 422);
}
if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
    json_response(['success' => false, 'message' => 'Invalid latitude.'], 422);
}
if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
    json_response(['success' => false, 'message' => 'Invalid longitude.'], 422);
}

try {
    $pdo->beginTransaction();

    if ($setDefault) {
        $clear = $pdo->prepare(
            'UPDATE buyer_addresses SET is_default = 0 WHERE buyer_user_id = :uid'
        );
        $clear->execute(['uid' => $buyerUserId]);
    }

    if ($addressId > 0) {
        $own = $pdo->prepare(
            'SELECT id FROM buyer_addresses WHERE id = :id AND buyer_user_id = :uid LIMIT 1'
        );
        $own->execute(['id' => $addressId, 'uid' => $buyerUserId]);
        if (!$own->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'Address not found.'], 404);
        }

        $up = $pdo->prepare(
            'UPDATE buyer_addresses SET
                label = :label,
                contact_name = :contact_name,
                contact_phone = :contact_phone,
                address_line1 = :address_line1,
                address_line2 = :address_line2,
                barangay = :barangay,
                city = :city,
                province = :province,
                postal_code = :postal_code,
                notes = :notes,
                latitude = :latitude,
                longitude = :longitude,
                is_default = :is_default
             WHERE id = :id AND buyer_user_id = :uid'
        );
        $up->execute([
            'label' => $label,
            'contact_name' => $contactName,
            'contact_phone' => $contactPhone,
            'address_line1' => $addressLine1,
            'address_line2' => $addressLine2,
            'barangay' => $barangay,
            'city' => $city,
            'province' => $province,
            'postal_code' => $postalCode,
            'notes' => $notes,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'is_default' => $setDefault ? 1 : 0,
            'id' => $addressId,
            'uid' => $buyerUserId,
        ]);
        $savedId = $addressId;
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO buyer_addresses (
                buyer_user_id, label, contact_name, contact_phone,
                address_line1, address_line2, barangay, city, province,
                postal_code, notes, latitude, longitude, is_default
             ) VALUES (
                :uid, :label, :contact_name, :contact_phone,
                :address_line1, :address_line2, :barangay, :city, :province,
                :postal_code, :notes, :latitude, :longitude, :is_default
             )'
        );
        $ins->execute([
            'uid' => $buyerUserId,
            'label' => $label,
            'contact_name' => $contactName,
            'contact_phone' => $contactPhone,
            'address_line1' => $addressLine1,
            'address_line2' => $addressLine2,
            'barangay' => $barangay,
            'city' => $city,
            'province' => $province,
            'postal_code' => $postalCode,
            'notes' => $notes,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'is_default' => $setDefault ? 1 : 0,
        ]);
        $savedId = (int)$pdo->lastInsertId();
    }

    $pdo->commit();

    $fetch = $pdo->prepare(
        'SELECT id, buyer_user_id, label, contact_name, contact_phone,
                address_line1, address_line2, barangay, city, province,
                postal_code, latitude, longitude, is_default
         FROM buyer_addresses
         WHERE id = :id LIMIT 1'
    );
    $fetch->execute(['id' => $savedId]);
    $row = $fetch->fetch();

    json_response([
        'success' => true,
        'message' => 'Delivery address saved.',
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
            'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
            'is_default' => (int)($row['is_default'] ?? 0),
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('buyer/save-address: ' . $e->getMessage());
    json_response([
        'success' => false,
        'message' => 'Failed to save your delivery address.',
        'error' => $e->getMessage(),
    ], 500);
}
