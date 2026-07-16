<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$phone = trim((string)($input['phone'] ?? ''));
$purpose = strtolower(trim((string)($input['purpose'] ?? 'login')));
$role = strtolower(trim((string)($input['role'] ?? 'buyer')));

if ($phone === '') {
    json_response(['success' => false, 'message' => 'Phone is required.'], 422);
}

// Must be E.164 (e.g. +639171234567). Flutter normalizes before sending.
if (!preg_match('/^\+\d{8,15}$/', $phone)) {
    json_response([
        'success' => false,
        'message' => 'Enter a valid mobile number in international format (e.g. +639171234567).',
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

    if ($purpose === 'login') {
        // Only send OTP to phones already registered with the requested role.
        // This stops attackers from burning SMS credit on random numbers via login.
        $st = $pdo->prepare(
            'SELECT id FROM users WHERE phone = ? AND role = ? AND is_active = 1 LIMIT 1'
        );
        $st->execute([$phone, $role]);
        if (!$st->fetch()) {
            $label = $role === 'seller' ? 'seller' : 'buyer';
            json_response([
                'success' => false,
                'message' => 'No ' . $label . ' account is registered with that number.',
            ], 404);
        }
    } else {
        // purpose === 'signup': refuse if a user already exists with that phone, so
        // we don't waste an SMS confirming a number that can't complete signup.
        $st = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $st->execute([$phone]);
        if ($st->fetch()) {
            json_response([
                'success' => false,
                'message' => 'An account already exists with that number. Please log in instead.',
            ], 409);
        }
    }

    $url = 'https://verify.twilio.com/v2/Services/' . rawurlencode($serviceSid) . '/Verifications';
    $body = http_build_query([
        'To' => $phone,
        'Channel' => 'sms',
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

    if ($httpCode >= 200 && $httpCode < 300) {
        json_response([
            'success' => true,
            'message' => 'OTP sent.',
        ]);
    }

    // Twilio returns helpful error messages but they can leak provider details — keep
    // the user-facing text generic while logging the real message on the server.
    $twilioMessage = (string)($decoded['message'] ?? 'Could not send OTP.');
    error_log('[otp-send] Twilio error code=' . ($decoded['code'] ?? '?') . ' msg=' . $twilioMessage);
    json_response([
        'success' => false,
        'message' => 'Could not send OTP. Make sure the number can receive SMS and try again.',
    ], 400);
} catch (Throwable $e) {
    error_log('[otp-send] exception: ' . $e->getMessage());
    json_response([
        'success' => false,
        'message' => 'Could not send OTP right now. Please try again.',
    ], 500);
}
