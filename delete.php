<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

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
    flash('error', 'Das Bild wurde nicht gefunden.');
} elseif (!image_may_delete($image, $user)) {
    flash('error', 'Du darfst dieses Bild nicht löschen.');
} else {
    image_delete($id);
    flash('success', 'Das Bild wurde gelöscht.');
}

redirect($return);
