<?php
declare(strict_types=1);

/**
 * Wird von jeder Seite als Erstes eingebunden.
 */

require_once __DIR__ . '/config.php';

// Fallbacks, falls mbstring auf dem Server fehlt
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $s, ?string $enc = null): string
    {
        return strtolower($s);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $enc = null): int
    {
        return strlen($s);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null, ?string $enc = null): string
    {
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }
}

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/likes.php';
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/birthdays.php';
require_once __DIR__ . '/meals.php';

date_default_timezone_set(APP_TIMEZONE);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

store_ensure_dirs();
session_boot();
