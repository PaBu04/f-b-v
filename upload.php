<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

/*
 * Die Seite schickt den Upload per XMLHttpRequest und erwartet dann JSON.
 * Die Rückmeldungen bleiben trotzdem als Flash-Nachrichten in der Session –
 * sie erscheinen beim anschließenden Seitenwechsel wie gewohnt.
 */
$wantsJson = strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

/** Beendet die Anfrage – als JSON oder als Weiterleitung. */
function upload_finish(bool $wantsJson, bool $ok, int $saved = 0, array $errors = []): void
{
    if ($wantsJson) {
        /*
         * Überschreitet die Anfrage post_max_size, meldet PHP das bereits vor
         * dem ersten Befehl dieses Skripts. Steht die Fehleranzeige an, ist
         * dann schon Text unterwegs – dann bleibt es beim reinen Statuscode,
         * den die Seite ohnehin auswertet.
         */
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode(['ok' => $ok, 'saved' => $saved, 'errors' => $errors]);
        exit;
    }

    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

// Bei Überschreitung von post_max_size ist $_POST komplett leer
if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $meldung = 'Die Daten waren insgesamt zu groß (Serverlimit: '
        . format_bytes(ini_bytes((string) ini_get('post_max_size')))
        . '). Bitte weniger Bilder auf einmal hochladen.';
    flash('error', $meldung);
    upload_finish($wantsJson, false, 0, [$meldung]);
}

csrf_require();

$caption = (string) ($_POST['caption'] ?? '');
$files   = $_FILES['files'] ?? null;

if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
    flash('error', 'Es wurde keine Datei ausgewählt.');
    upload_finish($wantsJson, false, 0, ['Es wurde keine Datei ausgewählt.']);
}

$saved  = 0;
$errors = [];

$count = count($files['name']);
for ($i = 0; $i < $count; $i++) {
    if ((int) $files['error'][$i] === UPLOAD_ERR_NO_FILE) {
        continue;
    }

    $file = [
        'name'     => $files['name'][$i],
        'type'     => $files['type'][$i],
        'tmp_name' => $files['tmp_name'][$i],
        'error'    => $files['error'][$i],
        'size'     => $files['size'][$i],
    ];

    list($record, $error) = image_store($file, $user, $caption);
    if ($record !== null) {
        $saved++;
    } else {
        $errors[] = (string) $error;
    }
}

if ($saved > 0) {
    flash('success', $saved === 1 ? 'Ein Bild wurde hochgeladen.' : $saved . ' Bilder wurden hochgeladen.');
}
foreach (array_slice($errors, 0, 5) as $error) {
    flash('error', $error);
}
if ($saved === 0 && $errors === []) {
    flash('error', 'Es wurde keine Datei ausgewählt.');
}
if (count($errors) > 5) {
    flash('error', 'Weitere ' . (count($errors) - 5) . ' Dateien konnten nicht verarbeitet werden.');
}

if ($saved > 0) {
    /*
     * Läuft erst, wenn das Skript endet. Der erste Schritt schließt die
     * Antwort an den Browser ab – sonst würde der Uploadende warten, bis alle
     * fremden Push-Dienste geantwortet haben.
     */
    register_shutdown_function(static function () use ($saved, $user) {
        push_detach();

        push_notify(
            push_recipients('uploads', (string) $user['id']),
            [
                'title' => $saved === 1 ? 'Ein neues Bild' : $saved . ' neue Bilder',
                'body'  => $user['nickname'] . ' hat '
                    . ($saved === 1 ? 'ein Bild' : $saved . ' Bilder') . ' hochgeladen.',
                'url'   => 'index.php',
                'tag'   => 'upload',
            ]
        );
    });
}

upload_finish($wantsJson, $saved > 0, $saved, $errors);
