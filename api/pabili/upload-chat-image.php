<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    json_response(['success' => false, 'message' => 'Image file is required (field name: image).'], 422);
}

$file = $_FILES['image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['success' => false, 'message' => 'Upload failed.'], 400);
}

$tmp = (string)$file['tmp_name'];
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmp);
if ($mime === false || !isset($allowed[$mime])) {
    json_response(['success' => false, 'message' => 'Only JPEG, PNG, WebP, and GIF images are allowed.'], 400);
}

try {
    $pdo = db();
    $buyerUserId = (int)($_POST['buyer_user_id'] ?? 0);
    $riderUserId = (int)($_POST['rider_user_id'] ?? 0);
    sukiwave_require_pabili_pair_auth($pdo, $buyerUserId, $riderUserId);

    $dir = __DIR__ . '/../uploads/pabili';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $ext = $allowed[$mime];
    $basename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $basename;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    $relative = 'uploads/pabili/' . $basename;
    $base = sukiwave_public_api_root_url();
    $fullUrl = rtrim($base, '/') . '/' . $relative;

    json_response([
        'success' => true,
        'data' => [
            'path' => $relative,
            'url' => $fullUrl,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Upload failed.',
        'error' => $e->getMessage(),
    ], 400);
}
