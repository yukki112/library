<?php
// Upload endpoint for message attachments.
// Allows authenticated users to upload a single file and returns a JSON
// response containing the URL and original filename.  The file is stored
// under the public/uploads directory so that it can be served directly
// via the web server.  Only POST requests with a multipart/form‑data
// payload containing a `file` field are accepted.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

start_app_session();
$user = current_user();
if (!$user) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Ensure a file was uploaded
if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

// Determine upload directory.  Use the uploads folder under public.  Create it
// if it does not exist.  Because APP_BASE_URL may contain special
// characters, derive the absolute path relative to this script.
$publicDir = realpath(__DIR__ . '/../public');
$uploadDir = $publicDir . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$origName = basename($_FILES['file']['name']);
// Sanitize the original filename to avoid directory traversal and other
// unsafe characters.  Only allow letters, numbers, dots, underscores and
// hyphens.  Anything else is replaced with an underscore.
$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
$ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));

// Generate a unique filename to prevent collisions.  Use a timestamp and
// random bytes for unpredictability.  Preserve the sanitized extension.
$unique = bin2hex(random_bytes(6));
$finalName = time() . '_' . $unique . ($ext ? ('.' . $ext) : '');

// Move the uploaded file into place.  Use move_uploaded_file to ensure
// the upload is secure.  If the move fails, return an error.
$target = $uploadDir . DIRECTORY_SEPARATOR . $finalName;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to save uploaded file']);
    exit;
}

// Build a URL relative to the public directory.  This will be served
// directly by the web server when referenced by clients.  Use forward
// slashes for URLs regardless of OS directory separators.
$url = 'uploads/' . $finalName;
header('Content-Type: application/json');
echo json_encode([
    'url'  => $url,
    'name' => $origName
]);
?>