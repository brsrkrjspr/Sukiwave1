<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$phone = trim((string)($input['phone'] ?? ''));
$code = trim((string)($input['code'] ?? ''));
$purpose = strtolower(trim((string)($input['purpose'] ?? 'login')));
$role = strtolower(trim((string)($input['role'] ?? 'buyer')));

if ($phone === '' || $code === '') {
    json_response([
        'success' => false,
        'message' => 'Phone and code are required.',
    ], 422);
}

if (!preg_match('/^\+\d{8,15}$/', $phone)) {
    json_response([
        'success' => false,
        'message' => 'Enter a valid mobile number in international format.',
    ], 422);
}

if (!preg_match('/^\d{4,10}$/', $code)) {
    json_response([
        'success' => false,
        'message' => 'Enter the OTP code you received via SMS.',
    ], 422);
}

if (!in_array($purpose, ['login', 'signup'], true)) {
    $purpose = 'login';
}
if (!in_array($role, ['buyer', 'seller'], true)) {
    $role = 'buyer';
}

$accountSid = (string)env_or_default('TWILIO_ACCOUNT_SID', '');
$authToken = (string)env_or_default('TWILIO_AUTH_TOKEN', '');
$serviceSid = (string)env_or_default('TWILIO_VERIFY_SERVICE_SID', '');

if ($accountSid === '' || $authToken === '' || $serviceSid === '') {
    json_response([
        'success' => false,
        'message' => 'OTP service is not configured. Contact support.',
    ], 503);
}

try {
    $pdo = db();

    $url = 'https://verify.twilio.com/v2/Services/' . rawurlencode($serviceSid) . '/VerificationCheck';
    $body = http_build_query([
        'To' => $phone,
        'Code' => $code,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_USERPWD => $accountSid . ':' . $authToken,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);

    if ($response === false) {
        json_response([
            'success' => false,
            'message' => 'Could not reach the OTP provider. Please try again.',
            'error' => $curlErr,
        ], 502);
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) {
        json_response([
            'success' => false,
            'message' => 'Unexpected OTP provider response.',
        ], 502);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $twilioMessage = (string)($decoded['message'] ?? 'OTP verification failed.');
        error_log('[otp-verify] Twilio error code=' . ($decoded['code'] ?? '?') . ' msg=' . $twilioMessage);
        json_response([
            'success' => false,
            'message' => 'Could not verify code. Request a new OTP and try again.',
        ], 400);
    }

    $status = strtolower((string)($decoded['status'] ?? ''));
    if ($status !== 'approved') {
        json_response([
            'success' => false,
            'message' => 'Invalid or expired OTP code.',
        ], 401);
    }

    if ($purpose === 'signup') {
        // Just confirm the phone owns the OTP. The signup endpoint
        // (auth/register.php) will create the user next.
        json_response([
            'success' => true,
            'message' => 'Phone number verified.',
            'data' => ['verified' => true],
        ]);
    }

    // purpose === 'login': look up the user with the requested role and issue a token.
    $hasBuyerProfileImage = sukiwave_users_has_profile_image_column($pdo);
    $buyerProfileImageSelect = $hasBuyerProfileImage
        ? 'u.profile_image_url AS profile_image_url,'
        : 'NULL AS profile_image_url,';

    $stmt = $pdo->prepare(
        "SELECT u.id, u.role, u.full_name, u.email, u.phone, {$buyerProfileImageSelect} u.is_active,
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
         WHERE u.phone = :phone AND u.role = :role
         LIMIT 1"
    );
    $stmt->execute(['phone' => $phone, 'role' => $role]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        $label = $role === 'seller' ? 'seller' : 'buyer';
        json_response([
            'success' => false,
            'message' => 'No active ' . $label . ' account is registered with that number.',
        ], 404);
    }

    unset($user['is_active']);

    $data = $user;
    $data[$role . '_api_token'] = sukiwave_issue_role_token((int)$user['id'], $role);

    json_response([
        'success' => true,
        'message' => 'Login successful.',
        'data' => $data,
    ]);
} catch (Throwable $e) {
    error_log('[otp-verify] exception: ' . $e->getMessage());
    json_response([
        'success' => false,
        'message' => 'Could not verify OTP right now. Please try again.',
    ], 500);
}
