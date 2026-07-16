<?php
declare(strict_types=1);

/**
 * Verifies a Firebase Auth ID token (RS256) using Google's x509 certs for
 * securetoken@system.gserviceaccount.com. Uses OpenSSL only (no Composer).
 *
 * @return object Decoded JWT payload as object (sub, email, iss, aud, …)
 *
 * @throws RuntimeException on invalid token or verification failure
 */
function sukiwave_verify_firebase_id_token(string $idToken, string $projectId): object
{
    if ($projectId === '') {
        throw new RuntimeException('Firebase project id is not configured.');
    }

    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        throw new RuntimeException('Invalid ID token format.');
    }

    $headerRaw = sukiwave_base64url_decode_string($parts[0]);
    $payloadRaw = sukiwave_base64url_decode_string($parts[1]);
    $sigRaw = sukiwave_base64url_decode_binary($parts[2]);
    if ($headerRaw === null || $payloadRaw === null || $sigRaw === null) {
        throw new RuntimeException('Invalid ID token segments.');
    }

    $header = json_decode($headerRaw, true);
    if (!is_array($header) || !isset($header['kid']) || !is_string($header['kid'])) {
        throw new RuntimeException('Invalid ID token header.');
    }
    $kid = $header['kid'];

    $certs = sukiwave_fetch_firebase_x509_cert_map();
    if (!isset($certs[$kid])) {
        throw new RuntimeException('Invalid ID token signing key. Try again shortly.');
    }
    $pem = $certs[$kid];

    $pubKey = openssl_pkey_get_public($pem);
    if ($pubKey === false) {
        throw new RuntimeException('Invalid Firebase public key.');
    }

    $signedData = $parts[0] . '.' . $parts[1];
    $ok = openssl_verify($signedData, $sigRaw, $pubKey, OPENSSL_ALGO_SHA256);

    if ($ok !== 1) {
        throw new RuntimeException('Invalid ID token signature.');
    }

    $payload = json_decode($payloadRaw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid ID token payload.');
    }

    $now = time();
    $exp = (int)($payload['exp'] ?? 0);
    if ($exp > 0 && $exp < $now) {
        throw new RuntimeException('ID token has expired.');
    }

    $expectedIss = 'https://securetoken.google.com/' . $projectId;
    if (($payload['iss'] ?? '') !== $expectedIss) {
        throw new RuntimeException('Invalid ID token issuer.');
    }

    $aud = $payload['aud'] ?? null;
    if (is_array($aud)) {
        $audOk = in_array($projectId, $aud, true);
    } else {
        $audOk = ((string)$aud === $projectId);
    }
    if (!$audOk) {
        throw new RuntimeException('Invalid ID token audience.');
    }

    $obj = json_decode($payloadRaw, false);
    if (!is_object($obj)) {
        throw new RuntimeException('Invalid ID token payload.');
    }
    return $obj;
}

function sukiwave_base64url_decode_string(string $segment): ?string
{
    $bin = sukiwave_base64url_decode_binary($segment);
    if ($bin === null) {
        return null;
    }
    return $bin;
}

function sukiwave_base64url_decode_binary(string $segment): ?string
{
    $remainder = strlen($segment) % 4;
    if ($remainder > 0) {
        $segment .= str_repeat('=', 4 - $remainder);
    }
    $raw = base64_decode(strtr($segment, '-_', '+/'), true);
    return $raw === false ? null : $raw;
}

/**
 * @return array<string, string> kid => PEM
 */
function sukiwave_fetch_firebase_x509_cert_map(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $url = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false || $json === '') {
        throw new RuntimeException('Could not load Firebase public keys.');
    }
    $map = json_decode($json, true);
    if (!is_array($map)) {
        throw new RuntimeException('Invalid Firebase public key response.');
    }
    $cache = $map;
    return $cache;
}

function sukiwave_firebase_project_id(): string
{
    $raw = getenv('SUKIWAVE_FIREBASE_PROJECT_ID');
    if (is_string($raw) && trim($raw) !== '') {
        return trim($raw);
    }
    return 'suki-ef931';
}
