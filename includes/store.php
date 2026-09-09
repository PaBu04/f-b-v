<?php
declare(strict_types=1);

/**
 * Minimaler JSON-Datenspeicher mit Dateisperren.
 *
 * store_read()   liest eine Sammlung (Shared Lock).
 * store_mutate() liest, verändert und schreibt atomar (Exclusive Lock).
 */

function store_ensure_dirs(): void
{
    foreach ([DATA_DIR, UPLOAD_DIR, THUMB_DIR, AVATAR_DIR, RECIPE_DIR] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}

function store_path(string $collection): string
{
    return DATA_DIR . '/' . $collection . '.json';
}

/**
 * @return array<int, array<string, mixed>>
 */
function store_read(string $collection): array
{
    $path = store_path($collection);
    if (!is_file($path)) {
        return [];
    }
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return [];
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    $data = json_decode((string) $raw, true);

    return is_array($data) ? $data : [];
}

/**
 * Führt $mutator auf der Sammlung aus. Der Callback bekommt das Array per
 * Referenz und darf einen beliebigen Rückgabewert liefern, der durchgereicht
 * wird (z. B. den neu angelegten Datensatz).
 *
 * @param callable(array): mixed $mutator  Signatur: function (array &$rows)
 * @return mixed
 */
function store_mutate(string $collection, callable $mutator)
{
    store_ensure_dirs();
    $path = store_path($collection);

    $fh = @fopen($path, 'c+b');
    if ($fh === false) {
        throw new RuntimeException('Datenspeicher nicht beschreibbar: ' . $collection);
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException('Datenspeicher gesperrt: ' . $collection);
    }

    try {
        $raw  = stream_get_contents($fh);
        $rows = json_decode((string) $raw, true);
        if (!is_array($rows)) {
            $rows = [];
        }

        $result = $mutator($rows);

        $json = json_encode(
            array_values($rows),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            throw new RuntimeException('Daten konnten nicht kodiert werden.');
        }

        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $json);
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    return $result;
}

/** Zufällige, kollisionsfreie ID. */
function store_new_id(): string
{
    return bin2hex(random_bytes(8));
}
