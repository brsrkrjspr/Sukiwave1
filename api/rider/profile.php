<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

$pdo = db();
if (!sukiwave_rider_profiles_has_compliance_columns($pdo)) {
    json_response([
        'success' => false,
        'message' => 'Database migration required: rider compliance columns are missing.',
    ], 503);
}

$hasHomeLoc = sukiwave_rider_profiles_has_default_location_columns($pdo);
$homeSelectCols = $hasHomeLoc
    ? 'rp.home_address_line1, rp.home_barangay, rp.home_city, rp.home_latitude, rp.home_longitude'
    : "NULL AS home_address_line1, NULL AS home_barangay, NULL AS home_city, NULL AS home_latitude, NULL AS home_longitude";

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    $riderUserId = sukiwave_require_rider_from_bearer($pdo);

    if ($method === 'GET') {
        $st = $pdo->prepare(
            'SELECT rp.user_id, rp.vehicle_type, rp.license_number, rp.registration_number, rp.valid_id_number,
                    rp.profile_image_url, rp.license_file_url, rp.registration_file_url, rp.valid_id_file_url,
                    rp.status, rp.last_seen_at,
                    ' . $homeSelectCols . '
             FROM rider_profiles rp
             WHERE rp.user_id = ?
             LIMIT 1'
        );
        $st->execute([$riderUserId]);
        $row = $st->fetch();
        if (!$row) {
            json_response(['success' => false, 'message' => 'Rider profile not found.'], 404);
        }
        json_response(['success' => true, 'data' => $row]);
    }

    if ($method !== 'POST') {
        json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $input = read_json_body();
    $vehicleType = trim((string)($input['vehicle_type'] ?? ''));
    $licenseNumber = trim((string)($input['license_number'] ?? ''));
    $registrationNumber = trim((string)($input['registration_number'] ?? ''));
    $validIdNumber = trim((string)($input['valid_id_number'] ?? ''));
    $profileImageUrl = trim((string)($input['profile_image_url'] ?? ''));
    $licenseFileUrl = trim((string)($input['license_file_url'] ?? ''));
    $registrationFileUrl = trim((string)($input['registration_file_url'] ?? ''));
    $validIdFileUrl = trim((string)($input['valid_id_file_url'] ?? ''));

    $allowedVehicleTypes = ['bike', 'motorcycle', 'car', 'van'];
    $vehicleTypeNorm = strtolower($vehicleType);
    if ($vehicleTypeNorm === 'bicycle') {
        $vehicleTypeNorm = 'bike';
    } elseif ($vehicleTypeNorm === 'tricycle') {
        $vehicleTypeNorm = 'motorcycle';
    }
    if ($vehicleTypeNorm !== '' && !in_array($vehicleTypeNorm, $allowedVehicleTypes, true)) {
        json_response(['success' => false, 'message' => 'Invalid vehicle type.'], 422);
    }

    $up = $pdo->prepare(
        'UPDATE rider_profiles
         SET vehicle_type = COALESCE(:vehicle_type, vehicle_type),
             license_number = :license_number,
             registration_number = :registration_number,
             valid_id_number = :valid_id_number,
             profile_image_url = COALESCE(:profile_image_url, profile_image_url),
             license_file_url = COALESCE(:license_file_url, license_file_url),
             registration_file_url = COALESCE(:registration_file_url, registration_file_url),
             valid_id_file_url = COALESCE(:valid_id_file_url, valid_id_file_url)
         WHERE user_id = :user_id'
    );
    $up->execute([
        'vehicle_type' => $vehicleTypeNorm !== '' ? $vehicleTypeNorm : null,
        'license_number' => $licenseNumber !== '' ? $licenseNumber : null,
        'registration_number' => $registrationNumber !== '' ? $registrationNumber : null,
        'valid_id_number' => $validIdNumber !== '' ? $validIdNumber : null,
        'profile_image_url' => $profileImageUrl !== '' ? $profileImageUrl : null,
        'license_file_url' => $licenseFileUrl !== '' ? $licenseFileUrl : null,
        'registration_file_url' => $registrationFileUrl !== '' ? $registrationFileUrl : null,
        'valid_id_file_url' => $validIdFileUrl !== '' ? $validIdFileUrl : null,
        'user_id' => $riderUserId,
    ]);

    $st = $pdo->prepare(
        'SELECT rp.user_id, rp.vehicle_type, rp.license_number, rp.registration_number, rp.valid_id_number,
                rp.profile_image_url, rp.license_file_url, rp.registration_file_url, rp.valid_id_file_url,
                rp.status, rp.last_seen_at,
                ' . $homeSelectCols . '
         FROM rider_profiles rp
         WHERE rp.user_id = ?
         LIMIT 1'
    );
    $st->execute([$riderUserId]);
    $row = $st->fetch();
    json_response(['success' => true, 'message' => 'Rider profile updated.', 'data' => $row]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Rider profile request failed.',
        'error' => $e->getMessage(),
    ], 400);
}

