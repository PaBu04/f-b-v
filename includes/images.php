<?php
declare(strict_types=1);

/**
 * Bild-Upload, Vorschaubilder und Galerie-Daten.
 *
 * Die Dateien liegen in uploads/ und sind per .htaccess direkt nicht
 * erreichbar. Ausgeliefert werden sie ausschließlich über image.php,
 * damit nur angemeldete Mitglieder sie sehen können.
 */

/**
 * @return array<int, array<string, mixed>> Neueste zuerst
 */
function images_all(): array
{
    $images = store_read('images');
    usort($images, static function (array $a, array $b) {
        return strcmp((string) ($b['uploaded_at'] ?? ''), (string) ($a['uploaded_at'] ?? ''));
    });

    return $images;
}

function image_by_id(string $id): ?array
{
    foreach (store_read('images') as $image) {
        if (($image['id'] ?? null) === $id) {
            return $image;
        }
    }

    return null;
}

function image_file_path(array $image, bool $thumb = false): string
{
    $name = $thumb ? (string) ($image['thumb'] ?? '') : (string) ($image['file'] ?? '');
    if ($name === '' || basename($name) !== $name) {
        return '';
    }

    return ($thumb ? THUMB_DIR : UPLOAD_DIR) . '/' . $name;
}

function image_mime(array $image): string
{
    switch ((string) ($image['ext'] ?? '')) {
        case 'png':
            return 'image/png';
        case 'gif':
            return 'image/gif';
        case 'webp':
            return 'image/webp';
        default:
            return 'image/jpeg';
    }
}

/**
 * Verarbeitet eine einzelne hochgeladene Datei.
 *
 * @param array<string, mixed> $file  Ein Eintrag aus $_FILES
 * @return array{0: ?array, 1: ?string} [Datensatz, Fehlertext]
 */
function image_store(array $file, array $user, string $caption = ''): array
{
    $originalName = (string) ($file['name'] ?? 'Bild');
    $errorCode    = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode !== UPLOAD_ERR_OK) {
        return [null, $originalName . ': ' . upload_error_text($errorCode)];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return [null, $originalName . ': Ungültiger Upload.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return [null, $originalName . ': Die Datei ist leer.'];
    }
    if ($size > MAX_UPLOAD_BYTES) {
        return [null, $originalName . ': Die Datei ist größer als ' . format_bytes(MAX_UPLOAD_BYTES) . '.'];
    }

    $info = @getimagesize($tmpPath);
    if ($info === false || empty($info[2])) {
        return [null, $originalName . ': Das ist keine gültige Bilddatei.'];
    }

    $type = (int) $info[2];
    if (!array_key_exists($type, ALLOWED_IMAGE_TYPES)) {
        return [null, $originalName . ': Dieses Bildformat wird nicht unterstützt (erlaubt: JPG, PNG, GIF, WEBP).'];
    }

    $ext = ALLOWED_IMAGE_TYPES[$type];

    store_ensure_dirs();

    $base      = date('Ymd-His') . '-' . bin2hex(random_bytes(6));
    $fileName  = $base . '.' . $ext;
    $thumbName = $base . '.jpg';

    $target = UPLOAD_DIR . '/' . $fileName;
    if (!@move_uploaded_file($tmpPath, $target)) {
        return [null, $originalName . ': Datei konnte nicht gespeichert werden (Schreibrechte prüfen).'];
    }
    @chmod($target, 0644);

    // Verkleinert wird im Browser, bevor das Bild losgeschickt wird
    // (siehe assets/app.js). Der Server nimmt es so, wie es ankommt.
    $width  = (int) $info[0];
    $height = (int) $info[1];

    if (!thumbnail_create($target, $type, THUMB_DIR . '/' . $thumbName)) {
        // Ohne GD oder bei Fehlern fällt die Anzeige auf das Original zurück
        $thumbName = '';
    }

    $record = [
        'id'                => store_new_id(),
        'file'              => $fileName,
        'thumb'             => $thumbName,
        'ext'               => $ext,
        'original_name'     => mb_substr($originalName, 0, 180),
        'caption'           => mb_substr(trim($caption), 0, 300),
        'width'             => $width,
        'height'            => $height,
        'size'              => $size,
        'user_id'           => (string) $user['id'],
        'uploader_nickname' => (string) $user['nickname'],
        'uploaded_at'       => date('c'),
    ];

    store_mutate('images', static function (array &$rows) use ($record) {
        $rows[] = $record;

        return true;
    });

    return [$record, null];
}

function upload_error_text(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Die Datei ist zu groß (Serverlimit: ' . format_bytes(effective_upload_limit()) . ').';
        case UPLOAD_ERR_PARTIAL:
            return 'Der Upload wurde abgebrochen.';
        case UPLOAD_ERR_NO_FILE:
            return 'Es wurde keine Datei ausgewählt.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Auf dem Server fehlt ein temporäres Verzeichnis.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Der Server konnte die Datei nicht schreiben.';
        case UPLOAD_ERR_EXTENSION:
            return 'Der Upload wurde von einer PHP-Erweiterung blockiert.';
        default:
            return 'Unbekannter Upload-Fehler.';
    }
}

/** Darf $user das Bild löschen? */
function image_may_delete(array $image, array $user): bool
{
    return !empty($user['is_admin']) || ($image['user_id'] ?? null) === ($user['id'] ?? null);
}

function image_delete(string $id): bool
{
    $image = image_by_id($id);
    if ($image === null) {
        return false;
    }

    foreach ([image_file_path($image, false), image_file_path($image, true)] as $path) {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    store_mutate('images', static function (array &$rows) use ($id) {
        $rows = array_values(array_filter($rows, static function (array $row) use ($id) {
            return ($row['id'] ?? null) !== $id;
        }));

        return true;
    });

    likes_remove_for_image($id);

    return true;
}

/* --------------------------------------------------------------------- */
/* Vorschaubilder                                                          */
/* --------------------------------------------------------------------- */

function gd_available(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
}

/**
 * Reicht der Speicher, um ein Bild dieser Größe mit GD zu öffnen?
 *
 * GD arbeitet unkomprimiert mit 4 Byte je Bildpunkt – ein 48-Megapixel-Foto
 * belegt damit rund 190 MB. Ohne diese Prüfung endet der Upload in einem
 * abgebrochenen Skript statt in einer verständlichen Meldung.
 */
function image_memory_available(int $width, int $height): bool
{
    $limit = ini_bytes((string) ini_get('memory_limit'));
    if ($limit <= 0) {
        return true; // unbegrenzt
    }

    $needed = $width * $height * 4 * 2 + 8 * 1024 * 1024; // Quelle, Ziel, Puffer

    return ($limit - memory_get_usage(true)) > $needed;
}

/**
 * Lädt ein Bild mit GD und dreht es gemäß EXIF-Ausrichtung.
 *
 * @return resource|object|null GD-Bild oder null
 */
function image_load_oriented(string $sourcePath, int $type)
{
    $source = null;
    switch ($type) {
        case IMAGETYPE_JPEG:
            if (function_exists('imagecreatefromjpeg')) {
                $source = @imagecreatefromjpeg($sourcePath);
            }
            break;
        case IMAGETYPE_PNG:
            if (function_exists('imagecreatefrompng')) {
                $source = @imagecreatefrompng($sourcePath);
            }
            break;
        case IMAGETYPE_GIF:
            if (function_exists('imagecreatefromgif')) {
                $source = @imagecreatefromgif($sourcePath);
            }
            break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagecreatefromwebp')) {
                $source = @imagecreatefromwebp($sourcePath);
            }
            break;
    }

    if (!$source) {
        return null;
    }

    // EXIF-Ausrichtung berücksichtigen (nur JPEG)
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        $orientation = (int) ($exif['Orientation'] ?? 0);
        if (in_array($orientation, [3, 6, 8], true) && function_exists('imagerotate')) {
            $angle = $orientation === 3 ? 180 : ($orientation === 6 ? -90 : 90);
            $rotated = @imagerotate($source, $angle, 0);
            if ($rotated) {
                imagedestroy($source);
                $source = $rotated;
            }
        }
    }

    return $source;
}

/** Schreibt ein GD-Bild als JPEG mit weißem Hintergrund. */
function image_write_jpeg($source, string $targetPath, int $dstW, int $dstH, int $srcX, int $srcY, int $srcW, int $srcH, int $quality = 82): bool
{
    $canvas = imagecreatetruecolor($dstW, $dstH);
    if (!$canvas) {
        return false;
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $dstW, $dstH, $white);
    imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH);

    $dir = dirname($targetPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $ok = @imagejpeg($canvas, $targetPath, $quality);
    imagedestroy($canvas);

    if ($ok) {
        @chmod($targetPath, 0644);
    }

    return (bool) $ok;
}

function thumbnail_create(string $sourcePath, int $type, string $targetPath): bool
{
    if (!gd_available()) {
        return false;
    }

    $info = @getimagesize($sourcePath);
    if ($info !== false && !image_memory_available((int) $info[0], (int) $info[1])) {
        return false;
    }

    $source = image_load_oriented($sourcePath, $type);
    if ($source === null) {
        return false;
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);
    if ($srcW < 1 || $srcH < 1) {
        imagedestroy($source);

        return false;
    }

    $scale = min(1.0, THUMB_MAX_EDGE / max($srcW, $srcH));
    $dstW  = max(1, (int) round($srcW * $scale));
    $dstH  = max(1, (int) round($srcH * $scale));

    // Weißer Hintergrund für transparente Quellen (Ziel ist JPEG)
    $ok = image_write_jpeg($source, $targetPath, $dstW, $dstH, 0, 0, $srcW, $srcH);

    imagedestroy($source);

    return $ok;
}

/* --------------------------------------------------------------------- */
/* Profilbilder                                                            */
/* --------------------------------------------------------------------- */

function avatar_file_path(?string $filename): string
{
    $filename = (string) $filename;
    if ($filename === '' || basename($filename) !== $filename) {
        return '';
    }

    return AVATAR_DIR . '/' . $filename;
}

function user_has_avatar(array $user): bool
{
    $path = avatar_file_path($user['avatar'] ?? '');

    return $path !== '' && is_file($path);
}

/** URL zum Profilbild; der Dateiname im Parameter sorgt für frische Caches. */
function avatar_url(array $user): string
{
    return 'avatar.php?u=' . urlencode((string) ($user['id'] ?? ''))
        . '&v=' . urlencode(substr((string) ($user['avatar'] ?? ''), 0, 24));
}

function avatar_initial(array $user): string
{
    $nickname = trim((string) ($user['nickname'] ?? ''));
    if ($nickname === '') {
        return '?';
    }
    $first = mb_substr($nickname, 0, 1);

    return function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
}

/** Immer gleiche Farbe je Mitglieder, solange kein Profilbild gesetzt ist. */
function avatar_hue(array $user): int
{
    return (int) (hexdec(substr(md5((string) ($user['id'] ?? '')), 0, 4)) % 360);
}

/** Profilbild oder Initiale als HTML. */
function avatar_html(array $user, string $extraClass = ''): string
{
    $class = trim('avatar ' . $extraClass);

    if (user_has_avatar($user)) {
        return '<img class="' . h($class) . '" src="' . h(avatar_url($user))
            . '" alt="" width="' . AVATAR_SIZE . '" height="' . AVATAR_SIZE . '" loading="lazy">';
    }

    return '<span class="' . h($class) . ' avatar-fallback" style="--avatar-hue: ' . avatar_hue($user)
        . '" aria-hidden="true">' . h(avatar_initial($user)) . '</span>';
}

/**
 * Speichert ein hochgeladenes Profilbild als quadratisches JPEG.
 *
 * @param array<string, mixed> $file Ein Eintrag aus $_FILES
 * @return array{0: ?string, 1: ?string} [Dateiname, Fehlertext]
 */
function avatar_store(array $file, array $user): array
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        return [null, 'Profilbild: ' . upload_error_text($errorCode)];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return [null, 'Profilbild: Ungültiger Upload.'];
    }

    if ((int) ($file['size'] ?? 0) > MAX_AVATAR_BYTES) {
        return [null, 'Das Profilbild darf höchstens ' . format_bytes(MAX_AVATAR_BYTES) . ' groß sein.'];
    }

    $info = @getimagesize($tmpPath);
    if ($info === false || empty($info[2]) || !array_key_exists((int) $info[2], ALLOWED_IMAGE_TYPES)) {
        return [null, 'Das Profilbild muss eine JPG-, PNG-, GIF- oder WEBP-Datei sein.'];
    }

    if (!gd_available()) {
        return [null, 'Profilbilder können nicht verarbeitet werden: Die GD-Erweiterung fehlt auf dem Server.'];
    }

    if (!image_memory_available((int) $info[0], (int) $info[1])) {
        return [null, 'Das Profilbild hat zu viele Bildpunkte für den Server. Bitte ein kleineres verwenden.'];
    }

    $source = image_load_oriented($tmpPath, (int) $info[2]);
    if ($source === null) {
        return [null, 'Das Profilbild konnte nicht gelesen werden.'];
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);
    if ($srcW < 1 || $srcH < 1) {
        imagedestroy($source);

        return [null, 'Das Profilbild konnte nicht gelesen werden.'];
    }

    // Mittigen quadratischen Ausschnitt wählen
    $edge = min($srcW, $srcH);
    $srcX = (int) round(($srcW - $edge) / 2);
    $srcY = (int) round(($srcH - $edge) / 2);
    $size = min(AVATAR_SIZE, $edge);

    store_ensure_dirs();
    $filename = 'avatar-' . bin2hex(random_bytes(8)) . '.jpg';

    $ok = image_write_jpeg($source, AVATAR_DIR . '/' . $filename, $size, $size, $srcX, $srcY, $edge, $edge, 88);
    imagedestroy($source);

    if (!$ok) {
        return [null, 'Das Profilbild konnte nicht gespeichert werden (Schreibrechte prüfen).'];
    }

    avatar_delete($user);

    return [$filename, null];
}

/** Entfernt die Profilbild-Datei eines Mitglieds (der Datensatz bleibt unberührt). */
function avatar_delete(array $user): void
{
    $path = avatar_file_path($user['avatar'] ?? '');
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}
