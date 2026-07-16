<?php
declare(strict_types=1);

/**
 * PayMongo REST helpers (Checkout Session + webhook signature).
 * Env: PAYMONGO_SECRET_KEY, PAYMONGO_WEBHOOK_SECRET (from PayMongo dashboard webhook).
 */

function paymongo_secret_key(): string
{
    $raw = getenv('PAYMONGO_SECRET_KEY');
    return is_string($raw) ? trim($raw) : '';
}

function paymongo_webhook_secret(): string
{
    $raw = getenv('PAYMONGO_WEBHOOK_SECRET');
    return is_string($raw) ? trim($raw) : '';
}

/**
 * Convert PHP order total (peso, 2 decimals) to PayMongo amount in centavos.
 */
function paymongo_peso_to_centavos(float $peso): int
{
    return (int)max(0, (int)round($peso * 100.0));
}

/**
 * @return list<string>
 */
function paymongo_default_payment_method_types(): array
{
    $raw = getenv('PAYMONGO_PAYMENT_METHOD_TYPES');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $x) {
                if (is_string($x) && $x !== '') {
                    $out[] = $x;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
    }
    return ['card', 'gcash', 'paymaya'];
}

/**
 * @param array<string, string> $metadata Values must be strings per PayMongo.
 */
function paymongo_create_checkout_session(
    int $amountCentavos,
    string $currency,
    string $description,
    array $metadata,
    string $successUrl,
    string $cancelUrl,
    array $paymentMethodTypes
): array {
    $sk = paymongo_secret_key();
    if ($sk === '') {
        throw new RuntimeException('PAYMONGO_SECRET_KEY is not set.');
    }
    if ($amountCentavos < 100) {
        throw new RuntimeException('Order total is too small for PayMongo (minimum 1.00 PHP).');
    }

    $lineItems = [
        [
            'currency' => $currency,
            'amount' => $amountCentavos,
            'name' => $description,
            'quantity' => 1,
        ],
    ];

    $payload = [
        'data' => [
            'attributes' => [
                'send_email_receipt' => false,
                'show_description' => true,
                'show_line_items' => true,
                'line_items' => $lineItems,
                'payment_method_types' => array_values($paymentMethodTypes),
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'description' => $description,
                'metadata' => $metadata,
            ],
        ],
    ];

    return paymongo_api_post('/checkout_sessions', $payload);
}

function paymongo_api_post(string $path, array $payload): array
{
    $sk = paymongo_secret_key();
    $url = 'https://api.paymongo.com/v1' . $path;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode PayMongo request.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('PayMongo request failed (curl init).');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_USERPWD => $sk . ':',
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0 || !is_string($raw)) {
        throw new RuntimeException('PayMongo request failed (network).');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('PayMongo returned invalid JSON.');
    }
    if ($code < 200 || $code >= 300) {
        $detail = paymongo_format_errors($decoded);
        throw new RuntimeException('PayMongo API error (' . $code . '): ' . $detail);
    }
    return $decoded;
}

function paymongo_format_errors(array $decoded): string
{
    $errors = $decoded['errors'] ?? null;
    if (!is_array($errors) || $errors === []) {
        return 'Unknown error';
    }
    $parts = [];
    foreach ($errors as $e) {
        if (is_array($e) && isset($e['detail'])) {
            $parts[] = (string)$e['detail'];
        }
    }
    return $parts !== [] ? implode('; ', $parts) : 'Unknown error';
}

/**
 * @return array{checkout_session_id: string, checkout_url: string, raw: array}
 */
function paymongo_parse_checkout_session_create_response(array $decoded): array
{
    $data = $decoded['data'] ?? null;
    if (!is_array($data)) {
        throw new RuntimeException('Unexpected PayMongo checkout response (missing data).');
    }
    $id = (string)($data['id'] ?? '');
    $attrs = $data['attributes'] ?? null;
    if ($id === '' || !is_array($attrs)) {
        throw new RuntimeException('Unexpected PayMongo checkout response (missing id/attributes).');
    }
    $url = (string)($attrs['checkout_url'] ?? '');
    if ($url === '') {
        throw new RuntimeException('PayMongo did not return checkout_url.');
    }
    return [
        'checkout_session_id' => $id,
        'checkout_url' => $url,
        'raw' => $decoded,
    ];
}

function paymongo_extract_signature_header(): string
{
    $candidates = [
        'HTTP_PAYMONGO_SIGNATURE',
        'HTTP_Paymongo_Signature',
    ];
    foreach ($candidates as $key) {
        $v = $_SERVER[$key] ?? '';
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Paymongo-Signature') === 0 && is_string($value)) {
                    return trim($value);
                }
            }
        }
    }
    return '';
}

/**
 * Verify Paymongo-Signature: HMAC-SHA256(webhook_secret, t + '.' + raw_payload).
 */
function paymongo_verify_webhook_signature(string $rawPayload, string $signatureHeader, string $webhookSecret): bool
{
    if ($webhookSecret === '' || $signatureHeader === '') {
        return false;
    }
    $parts = explode(',', $signatureHeader);
    $map = [];
    foreach ($parts as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) === 2) {
            $map[trim($kv[0])] = trim($kv[1]);
        }
    }
    $t = $map['t'] ?? '';
    $te = $map['te'] ?? '';
    $li = $map['li'] ?? '';
    if ($t === '') {
        return false;
    }
    $signedPayload = $t . '.' . $rawPayload;
    $expected = hash_hmac('sha256', $signedPayload, $webhookSecret);
    if ($te !== '' && hash_equals($te, $expected)) {
        return true;
    }
    if ($li !== '' && hash_equals($li, $expected)) {
        return true;
    }
    return false;
}

function paymongo_webhook_timestamp_skew_ok(string $signatureHeader, int $maxSkewSeconds = 600): bool
{
    $parts = explode(',', $signatureHeader);
    $map = [];
    foreach ($parts as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) === 2) {
            $map[trim($kv[0])] = trim($kv[1]);
        }
    }
    $t = isset($map['t']) ? (int)$map['t'] : 0;
    if ($t <= 0) {
        return false;
    }
    return abs(time() - $t) <= $maxSkewSeconds;
}

/**
 * @return array{type: string, checkout_session_id: string, metadata: array<string, string>}
 */
/**
 * PayMongo allows https return URLs in production; http is limited to local/dev hosts.
 */
function paymongo_is_allowed_return_url(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $lower = strtolower($url);
    if (str_starts_with($lower, 'https://')) {
        return true;
    }
    $httpHosts = [
        'http://localhost',
        'http://127.0.0.1',
        'http://10.0.2.2',
    ];
    foreach ($httpHosts as $prefix) {
        if (str_starts_with($lower, $prefix)) {
            return true;
        }
    }
    return false;
}

function paymongo_parse_checkout_paid_event(array $decoded): ?array
{
    $data = $decoded['data'] ?? null;
    if (!is_array($data) || ($data['type'] ?? '') !== 'event') {
        return null;
    }
    $attrs = $data['attributes'] ?? null;
    if (!is_array($attrs)) {
        return null;
    }
    $type = (string)($attrs['type'] ?? '');
    if ($type !== 'checkout_session.payment.paid') {
        return null;
    }
    $inner = $attrs['data'] ?? null;
    if (!is_array($inner) || ($inner['type'] ?? '') !== 'checkout_session') {
        return null;
    }
    $csId = (string)($inner['id'] ?? '');
    if ($csId === '') {
        return null;
    }
    $innerAttrs = $inner['attributes'] ?? null;
    $metadata = [];
    if (is_array($innerAttrs) && isset($innerAttrs['metadata']) && is_array($innerAttrs['metadata'])) {
        foreach ($innerAttrs['metadata'] as $k => $v) {
            $metadata[(string)$k] = (string)$v;
        }
    }
    return [
        'type' => $type,
        'checkout_session_id' => $csId,
        'metadata' => $metadata,
    ];
}
