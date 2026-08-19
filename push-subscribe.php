<?php
declare(strict_types=1);

/**
 * Nimmt Push-Abonnements des Browsers entgegen und löscht sie wieder.
 * Wird ausschließlich per fetch aus assets/app.js aufgerufen.
 */

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_user();
if ($user === null) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Nur POST.']);
    exit;
}

$daten = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($daten)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültige Daten.']);
    exit;
}

// CSRF-Prüfung: das Token steckt im JSON, nicht im Formular
$token = (string) ($daten['csrf'] ?? '');
if ($token === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiges Token.']);
    exit;
}

$aktion   = (string) ($daten['action'] ?? '');
$endpoint = (string) ($daten['endpoint'] ?? '');

if ($endpoint === '' || !preg_match('#^https://#', $endpoint)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Endpunkt.']);
    exit;
}

if ($aktion === 'unsubscribe') {
    push_unsubscribe($endpoint);
    echo json_encode(['ok' => true]);
    exit;
}

$p256dh = (string) ($daten['p256dh'] ?? '');
$auth   = (string) ($daten['auth'] ?? '');

if ($p256dh === '' || $auth === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Schlüssel fehlen.']);
    exit;
}

push_subscribe((string) $user['id'], $endpoint, $p256dh, $auth);

echo json_encode(['ok' => true]);
