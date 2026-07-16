<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$localEnvPath = __DIR__ . '/../config/local_env.php';
$bootstrapPath = __DIR__ . '/../config/bootstrap.php';
$bootstrapHash = is_file($bootstrapPath) ? substr(md5_file($bootstrapPath) ?: '', 0, 8) : 'missing';

function describe_env(string $key): array
{
    $raw = getenv($key);
    if ($raw === false) {
        return ['present' => false, 'length' => 0, 'starts_with' => null];
    }
    $val = (string)$raw;
    return [
        'present' => $val !== '',
        'length' => strlen($val),
        // First 2 chars only — enough to tell `AC...`, `VA...`, etc. from a wrong value, never the secret.
        'starts_with' => $val === '' ? null : substr($val, 0, 2),
    ];
}

echo json_encode([
    'bootstrap_php_md5_prefix' => $bootstrapHash,
    'local_env_php' => [
        'expected_path' => $localEnvPath,
        'exists' => is_file($localEnvPath),
        'readable' => is_file($localEnvPath) ? is_readable($localEnvPath) : false,
        'size_bytes' => is_file($localEnvPath) ? filesize($localEnvPath) : 0,
    ],
    'env' => [
        'TWILIO_ACCOUNT_SID' => describe_env('TWILIO_ACCOUNT_SID'),
        'TWILIO_AUTH_TOKEN' => describe_env('TWILIO_AUTH_TOKEN'),
        'TWILIO_VERIFY_SERVICE_SID' => describe_env('TWILIO_VERIFY_SERVICE_SID'),
    ],
    'php_version' => PHP_VERSION,
    'opcache_enabled' => function_exists('opcache_get_status')
        ? (bool)(opcache_get_status(false)['opcache_enabled'] ?? false)
        : false,
], JSON_PRETTY_PRINT);
