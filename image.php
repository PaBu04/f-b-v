<?php
declare(strict_types=1);

/**
 * Liefert Bilddateien aus. Nur für angemeldete Mitglieder erreichbar; die
 * Dateien selbst liegen in uploads/ und sind per .htaccess gesperrt.
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (current_user() === null) {
    http_response_code(403);
    exit;
}

$id    = (string) ($_GET['id'] ?? '');
$thumb = ($_GET['size'] ?? '') === 'thumb';

$image = $id !== '' ? image_by_id($id) : null;
if ($image === null) {
    http_response_code(404);
    exit;
}

$path = image_file_path($image, $thumb);
if ($thumb && ($path === '' || !is_file($path))) {
    // Kein Vorschaubild vorhanden -> Original ausliefern
    $thumb = false;
    $path  = image_file_path($image, false);
}

if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit;
}

$mime  = $thumb ? 'image/jpeg' : image_mime($image);
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

$downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($image['original_name'] ?? 'bild'));

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . $downloadName . '"');

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
