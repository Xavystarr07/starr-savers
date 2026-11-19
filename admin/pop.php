<?php
declare(strict_types=1);

/**
 * Starr Savers — /admin/pop.php?id=ORDER_ID
 * Streams a customer's proof of payment to a logged-in ADMIN only.
 * The /uploads/pop/ folder is blocked from direct web access (it holds bank details),
 * so this script is the only way to view a file.
 */

require_once __DIR__ . '/../includes/helpers.php';

start_session();
$u = current_user();
if (!$u || $u['role'] !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$stmt = db()->prepare('SELECT order_number, pop_file_path FROM orders WHERE id = :id LIMIT 1');
$stmt->execute([':id' => (int) ($_GET['id'] ?? 0)]);
$row = $stmt->fetch();

$rel = $row ? (string) $row['pop_file_path'] : '';
$file = STARR_ROOT . '/' . $rel;
// Only serve files that really live inside /uploads/pop/ (no path tricks).
$real = $rel !== '' ? realpath($file) : false;
$base = realpath(POP_UPLOAD_DIR);
if (!$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
    http_response_code(404);
    exit('No proof of payment on file.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($real) ?: 'application/octet-stream';
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit('Unsupported file.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($real)) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($real);
