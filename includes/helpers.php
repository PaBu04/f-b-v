<?php
declare(strict_types=1);

/** HTML-sichere Ausgabe. */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $target): void
{
    header('Location: ' . $target);
    exit;
}

/**
 * Zufällige Auflösung des Kürzels als Untertitel.
 * Die Wahl hält standardmäßig für die Dauer des Besuchs.
 */
function app_tagline(): string
{
    $taglines = APP_TAGLINES;
    if ($taglines === []) {
        return APP_TAGLINE;
    }

    if (TAGLINE_PER_REQUEST || session_status() !== PHP_SESSION_ACTIVE) {
        return (string) $taglines[array_rand($taglines)];
    }

    if (!isset($_SESSION['tagline']) || !in_array($_SESSION['tagline'], $taglines, true)) {
        $_SESSION['tagline'] = $taglines[array_rand($taglines)];
    }

    return (string) $_SESSION['tagline'];
}

/* --------------------------------------------------------------------- */
/* CSRF                                                                    */
/* --------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';

    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/** Bricht die Verarbeitung ab, wenn kein gültiges Token vorliegt. */
function csrf_require(): void
{
    if (!csrf_valid()) {
        http_response_code(400);
        exit('Ungültiges Formular-Token. Bitte die Seite neu laden.');
    }
}

/* --------------------------------------------------------------------- */
/* Flash-Nachrichten                                                       */
/* --------------------------------------------------------------------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * @return array<int, array{type: string, message: string}>
 */
function flash_take(): array
{
    $messages = isset($_SESSION['flash']) && is_array($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);

    return $messages;
}

/* --------------------------------------------------------------------- */
/* Formatierung                                                            */
/* --------------------------------------------------------------------- */

function format_datetime(?string $iso): string
{
    if (!$iso) {
        return '–';
    }
    $ts = strtotime($iso);

    return $ts ? date('d.m.Y, H:i', $ts) . ' Uhr' : '–';
}

function format_date(?string $iso): string
{
    if (!$iso) {
        return '–';
    }
    $ts = strtotime($iso);

    return $ts ? date('d.m.Y', $ts) : '–';
}

function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return number_format($value, $i === 0 ? 0 : 1, ',', '.') . ' ' . $units[$i];
}

/** Größtes tatsächlich mögliches Upload-Volumen (php.ini vs. Konfiguration). */
function effective_upload_limit(): int
{
    $limits = [MAX_UPLOAD_BYTES];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $raw = ini_get($key);
        if ($raw !== false && $raw !== '') {
            $limits[] = ini_bytes($raw);
        }
    }
    $limits = array_filter($limits, static function ($v) {
        return $v > 0;
    });

    return (int) min($limits);
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    $unit  = strtolower(substr($value, -1));
    $num   = (int) $value;

    if ($unit === 'g') {
        return $num * 1024 * 1024 * 1024;
    }
    if ($unit === 'm') {
        return $num * 1024 * 1024;
    }
    if ($unit === 'k') {
        return $num * 1024;
    }

    return $num;
}

function client_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';

    return substr($ip, 0, 45);
}

/** Aktuelle Datei ohne Query-String, für aktive Navigationspunkte. */
function current_script(): string
{
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
}
