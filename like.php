<?php
declare(strict_types=1);

/**
 * Schaltet den Like eines Mitglieds für ein Bild um.
 * Antwortet als JSON, wenn die Seite per JavaScript fragt – sonst per Redirect,
 * damit die Funktion auch ohne JavaScript nutzbar bleibt.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

$wantsJson = strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

csrf_require();

$id     = (string) ($_POST['id'] ?? '');
$return = (string) ($_POST['return'] ?? 'index.php');
if (!preg_match('/^[a-z0-9_.-]+\.php(\?[A-Za-z0-9_=&.-]*)?$/i', $return)) {
    $return = 'index.php';
}

$image = $id !== '' ? image_by_id($id) : null;

if ($image === null) {
    if ($wantsJson) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Bild nicht gefunden.']);
        exit;
    }
    flash('error', 'Das Bild wurde nicht gefunden.');
    redirect($return);
}

$result = like_toggle($id, (string) $user['id']);

// Nur beim Setzen benachrichtigen, und nie bei eigenen Bildern
$besitzer = (string) ($image['user_id'] ?? '');
if ($result['liked'] && $besitzer !== '' && $besitzer !== (string) $user['id']) {
    register_shutdown_function(static function () use ($besitzer, $user, $result) {
        push_detach();

        // Nur, wenn das Mitglied Like-Benachrichtigungen eingeschaltet hat
        if (!in_array($besitzer, push_recipients('likes', (string) $user['id']), true)) {
            return;
        }

        push_notify([$besitzer], [
            'title' => 'Gefällt jemandem',
            'body'  => $user['nickname'] . ' gefällt dein Bild'
                . ($result['count'] > 1 ? ' (' . $result['count'] . ' Likes)' : '') . '.',
            'url'   => 'index.php',
            'tag'   => 'like',
        ]);
    });
}

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'liked' => $result['liked'],
        'count' => $result['count'],
    ]);
    exit;
}

redirect($return);
