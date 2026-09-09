<?php
declare(strict_types=1);

/**
 * Liefert das Rezept eines Stammtischessens aus – Bild oder PDF.
 *
 * Nur für angemeldete Mitglieder erreichbar; die Dateien selbst liegen in
 * uploads/recipes und sind per .htaccess gesperrt.
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (current_user() === null) {
    http_response_code(403);
    exit;
}

$id   = (string) ($_GET['id'] ?? '');
$meal = $id !== '' ? meal_by_id($id) : null;
$rezept = $meal !== null ? meal_recipe($meal) : null;

if ($rezept === null) {
    http_response_code(404);
    exit;
}

$pfad = meal_recipe_path($rezept);
if ($pfad === '' || !is_file($pfad)) {
    http_response_code(404);
    exit;
}

$mime  = (string) ($rezept['mime'] ?? 'application/octet-stream');
$size  = (int) filesize($pfad);
$mtime = (int) filemtime($pfad);
$etag  = '"' . md5($pfad . '|' . $mtime . '|' . $size) . '"';

header('Cache-Control: private, max-age=604800');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

// Der Name kommt vom Hochladenden – alles außer Buchstaben, Ziffern, Punkt,
// Strich und Unterstrich fliegt raus, sonst ließe sich der Kopf manipulieren.
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($rezept['original_name'] ?? 'rezept'));
if ($name === '' || $name === null) {
    $name = 'rezept.' . (string) ($rezept['ext'] ?? 'dat');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($pfad);
