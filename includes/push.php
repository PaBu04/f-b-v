<?php
declare(strict_types=1);

/**
 * Web Push ohne Fremdbibliothek.
 *
 * Umgesetzt sind VAPID (RFC 8292) für die Absenderkennung und die
 * Nachrichtenverschlüsselung nach RFC 8291 / RFC 8188 (aes128gcm).
 * Gebraucht werden dafür nur OpenSSL und hash_hkdf – beides gehört seit
 * PHP 7.3 zum Standard.
 *
 * Ablauf einer Benachrichtigung:
 *   1. Browser abonniert und schickt Endpunkt + zwei Schlüssel an den Server.
 *   2. Server verschlüsselt die Nachricht mit diesen Schlüsseln.
 *   3. Server schickt sie an den Endpunkt des Push-Dienstes (Google, Apple …).
 *   4. Der Dienst stellt sie dem Gerät zu, der Service Worker zeigt sie an.
 */

const PUSH_TTL           = 86400;   // Sekunden, die der Dienst zwischenlagert
const PUSH_RECORD_SIZE   = 4096;
const PUSH_JWT_LIFETIME  = 43200;   // 12 Stunden

/* --------------------------------------------------------------------- */
/* Hilfsmittel                                                             */
/* --------------------------------------------------------------------- */

function push_b64_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function push_b64_decode(string $text): string
{
    return (string) base64_decode(strtr($text, '-_', '+/'));
}

/** Steht alles bereit, was für Web Push nötig ist? */
function push_available(): bool
{
    return function_exists('openssl_pkey_derive')
        && function_exists('hash_hkdf')
        && function_exists('openssl_encrypt')
        && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
}

/** Baut aus einem rohen P-256-Punkt (65 Byte) einen öffentlichen Schlüssel. */
function push_public_pem(string $point): string
{
    // Fester SubjectPublicKeyInfo-Kopf für id-ecPublicKey + prime256v1
    $der = (string) hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004')
        . substr($point, 1);

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/** Baut aus rohem Skalar und Punkt einen privaten P-256-Schlüssel. */
function push_private_pem(string $scalar, string $point): string
{
    $der = (string) hex2bin('3077020101' . '0420') . $scalar
        . (string) hex2bin('a00a06082a8648ce3d030107' . 'a14403420004')
        . substr($point, 1);

    return "-----BEGIN EC PRIVATE KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END EC PRIVATE KEY-----\n";
}

/** Rohen 65-Byte-Punkt aus einem OpenSSL-Schlüssel lesen. */
function push_point_of($key): string
{
    $details = openssl_pkey_get_details($key);

    return "\x04"
        . str_pad((string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad((string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
}

/** ES256-Signaturen liegen als DER vor, JWT erwartet R und S am Stück. */
function push_der_to_raw(string $der): string
{
    $position = 2;
    $teile    = [];

    for ($i = 0; $i < 2; $i++) {
        $laenge = ord($der[$position + 1]);
        $wert   = substr($der, $position + 2, $laenge);
        $wert   = ltrim($wert, "\x00");
        $teile[] = str_pad($wert, 32, "\x00", STR_PAD_LEFT);
        $position += 2 + $laenge;
    }

    return $teile[0] . $teile[1];
}

/* --------------------------------------------------------------------- */
/* VAPID-Schlüsselpaar                                                     */
/* --------------------------------------------------------------------- */

/**
 * Liefert das Schlüsselpaar des Servers und legt es beim ersten Aufruf an.
 * Es identifiziert diese Website gegenüber den Push-Diensten und darf sich
 * später nicht mehr ändern – sonst werden alle Abos ungültig.
 *
 * @return array{public: string, private: string, subject: string}|null
 */
function push_keys(?string &$fehler = null): ?array
{
    $fehler = null;

    if (!push_available()) {
        $fehler = 'Dem Server fehlen OpenSSL mit ECDH oder hash_hkdf.';

        return null;
    }

    $vorhanden = store_read('push_keys');
    if (isset($vorhanden[0]['public'], $vorhanden[0]['private'])) {
        return $vorhanden[0];
    }

    $schluessel = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);

    $pem = '';
    if ($schluessel === false || !openssl_pkey_export($schluessel, $pem)) {
        // Häufigste Ursache: OpenSSL findet seine Konfigurationsdatei nicht
        $meldungen = [];
        while ($zeile = openssl_error_string()) {
            $meldungen[] = $zeile;
        }
        $fehler = 'Der Schlüssel konnte nicht erzeugt werden'
            . ($meldungen !== [] ? ': ' . implode(' | ', array_slice($meldungen, 0, 2)) : '.');

        return null;
    }

    $paar = [
        'public'     => push_b64_encode(push_point_of($schluessel)),
        'private'    => $pem,
        'subject'    => 'mailto:admin@' . (string) ($_SERVER['HTTP_HOST'] ?? 'f-b-v.de'),
        'created_at' => date('c'),
    ];

    store_mutate('push_keys', static function (array &$rows) use ($paar) {
        if ($rows === []) {
            $rows[] = $paar;
        }

        return true;
    });

    $gespeichert = store_read('push_keys');

    return $gespeichert[0] ?? null;
}

/* --------------------------------------------------------------------- */
/* Verschlüsselung nach RFC 8291                                           */
/* --------------------------------------------------------------------- */

/**
 * Verschlüsselt eine Nachricht für ein Abonnement.
 *
 * @param string      $klartext   Der Nutzinhalt (JSON)
 * @param string      $uaPublic   Öffentlicher Schlüssel des Geräts, roh
 * @param string      $authSecret Gemeinsames Geheimnis des Abos, roh
 * @param string|null $salt       Nur für Tests vorgeben
 * @param mixed       $asKey      Nur für Tests vorgeben
 */
function push_encrypt(string $klartext, string $uaPublic, string $authSecret, ?string $salt = null, $asKey = null): string
{
    if ($asKey === null) {
        $asKey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
    }

    $asPublic = push_point_of($asKey);
    $gegenAn  = openssl_pkey_get_public(push_public_pem($uaPublic));
    if ($gegenAn === false) {
        throw new RuntimeException('Öffentlicher Schlüssel des Geräts ist ungültig.');
    }

    // Gemeinsames Geheimnis beider Seiten
    $geteilt = openssl_pkey_derive($gegenAn, $asKey, 32);
    if ($geteilt === false) {
        throw new RuntimeException('Schlüsselaustausch fehlgeschlagen.');
    }

    // RFC 8291, Abschnitt 3.3: das Geheimnis wird an beide Schlüssel gebunden
    $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
    $ikm     = hash_hkdf('sha256', $geteilt, 32, $keyInfo, $authSecret);

    $salt  = $salt ?? random_bytes(16);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    // RFC 8188: 0x02 schließt den letzten Abschnitt ab
    $tag    = '';
    $inhalt = openssl_encrypt($klartext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($inhalt === false) {
        throw new RuntimeException('Verschlüsselung fehlgeschlagen.');
    }

    return $salt
        . pack('N', PUSH_RECORD_SIZE)
        . chr(strlen($asPublic))
        . $asPublic
        . $inhalt
        . $tag;
}

/** Erzeugt den Authorization-Kopf mit dem VAPID-Nachweis für einen Endpunkt. */
function push_authorization(string $endpoint, array $keys): ?string
{
    $teile = parse_url($endpoint);
    if (!isset($teile['scheme'], $teile['host'])) {
        return null;
    }

    $kopf   = push_b64_encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $inhalt = push_b64_encode((string) json_encode([
        'aud' => $teile['scheme'] . '://' . $teile['host'],
        'exp' => time() + PUSH_JWT_LIFETIME,
        'sub' => (string) $keys['subject'],
    ]));

    $privat = openssl_pkey_get_private((string) $keys['private']);
    if ($privat === false) {
        return null;
    }

    $signatur = '';
    if (!openssl_sign($kopf . '.' . $inhalt, $signatur, $privat, OPENSSL_ALGO_SHA256)) {
        return null;
    }

    $jwt = $kopf . '.' . $inhalt . '.' . push_b64_encode(push_der_to_raw($signatur));

    return 'vapid t=' . $jwt . ', k=' . (string) $keys['public'];
}

/* --------------------------------------------------------------------- */
/* Abonnements                                                             */
/* --------------------------------------------------------------------- */

/**
 * @return array<int, array<string, mixed>>
 */
function push_subscriptions(?string $userId = null): array
{
    $alle = store_read('push_subscriptions');
    if ($userId === null) {
        return $alle;
    }

    return array_values(array_filter($alle, static function (array $abo) use ($userId) {
        return (string) ($abo['user_id'] ?? '') === $userId;
    }));
}

/** Legt ein Abo an oder aktualisiert es, falls der Endpunkt schon bekannt ist. */
function push_subscribe(string $userId, string $endpoint, string $p256dh, string $auth): void
{
    store_mutate('push_subscriptions', static function (array &$rows) use ($userId, $endpoint, $p256dh, $auth) {
        foreach ($rows as $index => $zeile) {
            if ((string) ($zeile['endpoint'] ?? '') === $endpoint) {
                $rows[$index] = array_merge($zeile, [
                    'user_id' => $userId,
                    'p256dh'  => $p256dh,
                    'auth'    => $auth,
                    'seen_at' => date('c'),
                ]);

                return true;
            }
        }

        $rows[] = [
            'id'         => store_new_id(),
            'user_id'    => $userId,
            'endpoint'   => $endpoint,
            'p256dh'     => $p256dh,
            'auth'       => $auth,
            'created_at' => date('c'),
            'seen_at'    => date('c'),
        ];

        return true;
    });
}

function push_unsubscribe(string $endpoint): void
{
    store_mutate('push_subscriptions', static function (array &$rows) use ($endpoint) {
        $rows = array_values(array_filter($rows, static function (array $zeile) use ($endpoint) {
            return (string) ($zeile['endpoint'] ?? '') !== $endpoint;
        }));

        return true;
    });
}

function push_unsubscribe_user(string $userId): void
{
    store_mutate('push_subscriptions', static function (array &$rows) use ($userId) {
        $rows = array_values(array_filter($rows, static function (array $zeile) use ($userId) {
            return (string) ($zeile['user_id'] ?? '') !== $userId;
        }));

        return true;
    });
}

/* --------------------------------------------------------------------- */
/* Versand                                                                 */
/* --------------------------------------------------------------------- */

/**
 * Schickt eine Nachricht an ein einzelnes Abo.
 *
 * @return int HTTP-Status des Push-Dienstes, 0 bei einem Fehler davor
 */
function push_deliver(array $abo, string $klartext, array $keys): int
{
    $endpoint = (string) ($abo['endpoint'] ?? '');
    if ($endpoint === '') {
        return 0;
    }

    try {
        $koerper = push_encrypt(
            $klartext,
            push_b64_decode((string) $abo['p256dh']),
            push_b64_decode((string) $abo['auth'])
        );
    } catch (Throwable $e) {
        return 0;
    }

    $authorization = push_authorization($endpoint, $keys);
    if ($authorization === null) {
        return 0;
    }

    $kopfzeilen = [
        'Authorization: ' . $authorization,
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'TTL: ' . PUSH_TTL,
        'Urgency: normal',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $koerper,
            CURLOPT_HTTPHEADER     => $kopfzeilen,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $status;
    }

    $kontext = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $kopfzeilen),
            'content'       => $koerper,
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);

    $antwort = @file_get_contents($endpoint, false, $kontext);
    if ($antwort === false && !isset($http_response_header)) {
        return 0;
    }

    foreach (($http_response_header ?? []) as $zeile) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $zeile, $treffer)) {
            return (int) $treffer[1];
        }
    }

    return 0;
}

/**
 * Benachrichtigt mehrere Mitglieder.
 *
 * @param array<int, string>  $userIds Empfänger
 * @param array<string, mixed> $inhalt title, body, url, tag
 */
function push_notify(array $userIds, array $inhalt): void
{
    if ($userIds === []) {
        return;
    }

    $keys = push_keys();
    if ($keys === null) {
        return;
    }

    $klartext = (string) json_encode($inhalt, JSON_UNESCAPED_UNICODE);
    $abgemeldet = [];

    foreach (push_subscriptions() as $abo) {
        if (!in_array((string) ($abo['user_id'] ?? ''), $userIds, true)) {
            continue;
        }

        $status = push_deliver($abo, $klartext, $keys);

        // 404/410: Das Gerät hat das Abo aufgegeben – aufräumen
        if ($status === 404 || $status === 410) {
            $abgemeldet[] = (string) $abo['endpoint'];
        }
    }

    foreach ($abgemeldet as $endpoint) {
        push_unsubscribe($endpoint);
    }
}

/** Empfänger für ein Ereignis: alle mit passender Einstellung außer dem Auslöser. */
function push_recipients(string $ereignis, string $ausser = ''): array
{
    $empfaenger = [];

    foreach (store_read('users') as $mitglied) {
        $id = (string) ($mitglied['id'] ?? '');
        if ($id === '' || $id === $ausser) {
            continue;
        }

        // Ohne gespeicherte Einstellung gilt: eingeschaltet
        $einstellungen = is_array($mitglied['notify'] ?? null) ? $mitglied['notify'] : [];
        if (!array_key_exists($ereignis, $einstellungen) || !empty($einstellungen[$ereignis])) {
            $empfaenger[] = $id;
        }
    }

    return $empfaenger;
}

/**
 * Beendet die Antwort an den Browser, bevor die Nachrichten verschickt werden.
 * Sonst wartet der Uploadende auf ein Dutzend fremder Push-Dienste.
 */
function push_detach(): void
{
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}
