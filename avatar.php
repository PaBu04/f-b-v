<?php
declare(strict_types=1);

/**
 * Liefert das Profilbild eines Mitglieds aus – nur für angemeldete Mitglieder.
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (current_user() === null) {
    http_response_code(403);
    exit;
}

$target = user_by_id((string) ($_GET['u'] ?? ''));
if ($target === null || !user_has_avatar($target)) {
    http_response_code(404);
    exit;
}

$path  = avatar_file_path((string) $target['avatar']);
$size  = (int) filesize($path);
$mtime = (int) filemtime($path);
$etag  = '"' . md5($path . '|' . $mtime . '|' . $size) . '"';

header('Cache-Control: private, max-age=604800');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . $size);

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
