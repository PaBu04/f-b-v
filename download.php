<?php
declare(strict_types=1);

/**
 * Lädt die Bilder der aktuellen Ansicht als ZIP-Archiv herunter.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/zip.php';

$user = require_login();

$filter   = ($_GET['filter'] ?? '') === 'mine' ? 'mine' : 'all';
$returnTo = 'index.php' . ($filter === 'mine' ? '?filter=mine' : '');

$images = images_all();
if ($filter === 'mine') {
    $images = array_values(array_filter($images, static function (array $image) use ($user) {
        return ($image['user_id'] ?? null) === $user['id'];
    }));
}

if ($images === []) {
    flash('info', 'Es gibt noch keine Bilder zum Herunterladen.');
    redirect($returnTo);
}

$usersById = users_by_id_map();

// Älteste zuerst, damit das Archiv chronologisch sortiert ist
$images = array_reverse($images);

$entries = [];
$used    = [];

foreach ($images as $image) {
    $path = image_file_path($image, false);
    if ($path === '' || !is_file($path)) {
        continue;
    }

    $owner    = $usersById[(string) ($image['user_id'] ?? '')] ?? null;
    $nickname = $owner !== null
        ? (string) $owner['nickname']
        : (string) ($image['uploader_nickname'] ?? 'Unbekannt');

    $uploaded = strtotime((string) ($image['uploaded_at'] ?? '')) ?: time();
    $original = pathinfo((string) ($image['original_name'] ?? 'bild'), PATHINFO_FILENAME);
    $extension = (string) ($image['ext'] ?? 'jpg');

    $name = date('Y-m-d', $uploaded) . '_' . $nickname . '_' . $original . '.' . $extension;

    // Gleiche Namen durchnummerieren, sonst überschreibt das Entpacken
    $key = mb_strtolower($name);
    if (isset($used[$key])) {
        $used[$key]++;
        $name = date('Y-m-d', $uploaded) . '_' . $nickname . '_' . $original
            . '-' . $used[$key] . '.' . $extension;
    } else {
        $used[$key] = 1;
    }

    $entries[] = [
        'path'  => $path,
        'name'  => $name,
        'mtime' => $uploaded,
    ];
}

if ($entries === []) {
    flash('error', 'Es konnten keine Bilddateien gefunden werden.');
    redirect($returnTo);
}

$archive = zip_prepare($entries);

if ($archive['files'] === []) {
    flash('error', 'Es konnten keine Bilddateien gelesen werden.');
    redirect($returnTo);
}

if ($archive['total'] > ZIP_MAX_TOTAL_BYTES) {
    flash('error', 'Die Bilder ergeben zusammen mehr als 4 GB – so viel passt nicht in ein Archiv. '
        . 'Bitte einzeln oder über den FTP-Zugang herunterladen.');
    redirect($returnTo);
}

$downloadName = 'f-b-v-bilder-' . ($filter === 'mine' ? 'meine-' : '') . date('Y-m-d') . '.zip';

zip_stream($archive['files'], $archive['total'], $downloadName);
