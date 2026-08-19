<?php
declare(strict_types=1);

/**
 * Schlanker ZIP-Erzeuger, der das Archiv direkt an den Browser streamt.
 *
 * Die Dateien werden ohne Komprimierung abgelegt ("stored"): Fotos sind
 * bereits komprimiert, das spart Rechenzeit und – wichtiger – es entsteht
 * keine temporäre Datei auf dem Webspace. Der Speicherbedarf bleibt konstant,
 * egal wie viele Bilder im Archiv landen.
 *
 * Grenze des klassischen ZIP-Formats: 4 GB pro Archiv (kein ZIP64).
 */

const ZIP_MAX_TOTAL_BYTES = 4294967295;

/** Wandelt einen Zeitstempel in das DOS-Format der ZIP-Spezifikation. */
function zip_dos_timestamp(int $timestamp): array
{
    if ((int) date('Y', $timestamp) < 1980) {
        $timestamp = (int) mktime(0, 0, 0, 1, 1, 1980);
    }

    $time = ((int) date('H', $timestamp) << 11)
        | ((int) date('i', $timestamp) << 5)
        | ((int) date('s', $timestamp) >> 1);

    $date = (((int) date('Y', $timestamp) - 1980) << 9)
        | ((int) date('n', $timestamp) << 5)
        | (int) date('j', $timestamp);

    return [$time, $date];
}

/** Entfernt alles, was in einem Archiv-Eintrag nichts zu suchen hat. */
function zip_safe_name(string $name): string
{
    $name = str_replace(['\\', '/'], '-', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    $name = trim((string) $name, '. ');

    return $name === '' ? 'bild' : $name;
}

/**
 * Sammelt Größe und Prüfsumme aller Einträge, damit Content-Length exakt
 * gesetzt werden kann (der Browser zeigt dann einen Fortschritt an).
 *
 * @param array<int, array{path: string, name: string, mtime: int}> $entries
 * @return array{files: array<int, array<string, mixed>>, total: int}
 */
function zip_prepare(array $entries): array
{
    $files  = [];
    $offset = 0;
    $cdSize = 0;

    foreach ($entries as $entry) {
        $path = (string) $entry['path'];
        if (!is_file($path)) {
            continue;
        }

        $crc = hash_file('crc32b', $path);
        if ($crc === false) {
            continue;
        }

        $name = zip_safe_name((string) $entry['name']);
        $size = (int) filesize($path);

        $files[] = [
            'path'   => $path,
            'name'   => $name,
            'size'   => $size,
            'crc'    => hexdec($crc),
            'mtime'  => (int) $entry['mtime'],
            'offset' => $offset,
        ];

        $offset += 30 + strlen($name) + $size;
        $cdSize += 46 + strlen($name);
    }

    return ['files' => $files, 'total' => $offset + $cdSize + 22];
}

/**
 * Schreibt das Archiv in die Ausgabe. Vorher darf nichts gesendet worden sein.
 *
 * @param array<int, array<string, mixed>> $files Ergebnis aus zip_prepare()
 */
function zip_stream(array $files, int $totalBytes, string $downloadName): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . $totalBytes);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    @set_time_limit(0);

    // Lokale Dateiköpfe und Dateiinhalte
    foreach ($files as $file) {
        list($dosTime, $dosDate) = zip_dos_timestamp((int) $file['mtime']);

        echo pack(
            'VvvvvvVVVvv',
            0x04034b50,          // Signatur
            20,                  // benötigte Version
            0x0800,              // Flags: Dateinamen sind UTF-8
            0,                   // Methode: gespeichert
            $dosTime,
            $dosDate,
            $file['crc'],
            $file['size'],       // komprimierte Größe
            $file['size'],       // Originalgröße
            strlen((string) $file['name']),
            0                    // Länge Zusatzfeld
        );
        echo $file['name'];

        $handle = fopen((string) $file['path'], 'rb');
        if ($handle === false) {
            // Datei ist zwischenzeitlich verschwunden: Platz trotzdem füllen,
            // damit Content-Length und die folgenden Offsets stimmen.
            $remaining = (int) $file['size'];
            while ($remaining > 0) {
                $chunk = min($remaining, 262144);
                echo str_repeat("\0", $chunk);
                $remaining -= $chunk;
            }
            continue;
        }

        $written = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 262144);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            $written += strlen($chunk);
            flush();
        }
        fclose($handle);

        // Falls die Datei kürzer geworden ist, den Rest auffüllen
        for ($missing = (int) $file['size'] - $written; $missing > 0; $missing -= 262144) {
            echo str_repeat("\0", (int) min($missing, 262144));
        }
    }

    // Zentrales Verzeichnis
    $cdStart = 0;
    foreach ($files as $file) {
        $cdStart = (int) $file['offset'] + 30 + strlen((string) $file['name']) + (int) $file['size'];
    }

    $cdSize = 0;
    foreach ($files as $file) {
        list($dosTime, $dosDate) = zip_dos_timestamp((int) $file['mtime']);
        $nameLength = strlen((string) $file['name']);

        echo pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,          // Signatur
            20,                  // erstellt mit Version
            20,                  // benötigte Version
            0x0800,              // Flags
            0,                   // Methode
            $dosTime,
            $dosDate,
            $file['crc'],
            $file['size'],
            $file['size'],
            $nameLength,
            0,                   // Zusatzfeld
            0,                   // Kommentar
            0,                   // Datenträger
            0,                   // interne Attribute
            0,                   // externe Attribute
            $file['offset']
        );
        echo $file['name'];

        $cdSize += 46 + $nameLength;
    }

    // Abschluss des zentralen Verzeichnisses
    $count = count($files);
    echo pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $count,
        $count,
        $cdSize,
        $cdStart,
        0
    );

    flush();
}
