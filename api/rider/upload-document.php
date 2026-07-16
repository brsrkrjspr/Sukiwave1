<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$pdo = db();
if (!sukiwave_rider_profiles_has_compliance_columns($pdo)) {
    json_response([
        'success' => false,
        'message' => 'Database migration required: rider compliance columns are missing.',
    ], 503);
}

$docType = strtolower(trim((string)($_POST['doc_type'] ?? '')));
$fieldMap = [
    'license' => 'license_file_url',
    'registration' => 'registration_file_url',
    'valid_id' => 'valid_id_file_url',
];
if (!isset($fieldMap[$docType])) {
    json_response(['success' => false, 'message' => 'doc_type must be license, registration, or valid_id.'], 422);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_response(['success' => false, 'message' => 'Document file is required (field name: file).'], 422);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['success' => false, 'message' => 'Upload failed.'], 400);
}

$tmp = (string)$file['tmp_name'];
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmp);
if ($mime === false || !isset($allowed[$mime])) {
    json_response(['success' => false, 'message' => 'Only JPEG, PNG, WebP, and PDF files are allowed.'], 400);
}

try {
    $riderUserId = sukiwave_require_rider_from_bearer($pdo);
    $dir = __DIR__ . '/../uploads/riders/docs/' . $docType;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $ext = $allowed[$mime];
    $basename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $basename;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    $relative = 'uploads/riders/docs/' . $docType . '/' . $basename;
    $fullUrl = rtrim(sukiwave_public_api_root_url(), '/') . '/' . $relative;
    $col = $fieldMap[$docType];
    $up = $pdo->prepare("UPDATE rider_profiles SET {$col} = ? WHERE user_id = ?");
    $up->execute([$relative, $riderUserId]);

    json_response(['success' => true, 'data' => ['path' => $relative, 'url' => $fullUrl]]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Upload failed.', 'error' => $e->getMessage()], 400);
}

