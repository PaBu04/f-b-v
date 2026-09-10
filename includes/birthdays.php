<?php
declare(strict_types=1);

/**
 * Geburtstage: Hinweis auf der Galerieseite und eine Benachrichtigung je Tag.
 *
 * Es gibt keinen Cron-Dienst auf dem Webspace, also stößt der erste Aufruf
 * der Galerie nach BIRTHDAY_NOTIFY_HOUR den Versand an. Damit dabei nicht
 * mehrere gleichzeitige Besuche denselben Gruß mehrfach verschicken, wird der
 * Tag vorher in der Sammlung „birthday_notices“ unter Sperre beansprucht –
 * wer den Eintrag anlegt, verschickt; alle anderen tun nichts.
 */

/** Wie viele vergangene Tage in birthday_notices aufgehoben werden. */
const BIRTHDAY_NOTICE_HISTORY = 30;

/* --------------------------------------------------------------------- */
/* Datum                                                                   */
/* --------------------------------------------------------------------- */

/**
 * Hat jemand mit diesem Geburtsdatum heute Geburtstag?
 *
 * Der 29. Februar wird in Jahren ohne Schalttag am 1. März gefeiert – so
 * rechnet auch § 188 BGB.
 */
function birthday_is_today(?string $birthdate, ?int $now = null): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim((string) $birthdate), $teile)) {
        return false;
    }

    $now   = $now ?? time();
    $monat = (int) $teile[2];
    $tag   = (int) $teile[3];

    if ($monat === (int) date('n', $now) && $tag === (int) date('j', $now)) {
        return true;
    }

    if ($monat === 2 && $tag === 29 && (int) date('n', $now) === 3 && (int) date('j', $now) === 1) {
        return (int) date('L', $now) === 0;
    }

    return false;
}

/** Alter, das heute erreicht wird – oder null bei unsinnigem Geburtsdatum. */
function birthday_age(?string $birthdate, ?int $now = null): ?int
{
    if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', trim((string) $birthdate), $teile)) {
        return null;
    }

    $alter = (int) date('Y', $now ?? time()) - (int) $teile[1];

    return ($alter > 0 && $alter < 130) ? $alter : null;
}

/**
 * Alle Mitglieder, die heute Geburtstag haben, nach Spitzname sortiert.
 *
 * @return array<int, array<string, mixed>>
 */
function birthdays_today(?int $now = null): array
{
    $now = $now ?? time();

    return array_values(array_filter(users_all(), static function (array $mitglied) use ($now) {
        return birthday_is_today((string) ($mitglied['birthdate'] ?? ''), $now);
    }));
}

/* --------------------------------------------------------------------- */
/* Texte                                                                   */
/* --------------------------------------------------------------------- */

/**
 * Fügt Namen zu einer deutschen Aufzählung zusammen: „Anna, Bernd und Carla“.
 *
 * @param array<int, string> $namen
 */
function birthday_names_text(array $namen): string
{
    $namen = array_values(array_filter($namen, static function (string $name) {
        return trim($name) !== '';
    }));

    if ($namen === []) {
        return '';
    }
    if (count($namen) === 1) {
        return $namen[0];
    }

    $letzter = array_pop($namen);

    return implode(', ', $namen) . ' und ' . $letzter;
}

/**
 * Satz für den Hinweis auf der Galerieseite und für die Benachrichtigung.
 *
 * @param array<int, array<string, mixed>> $kinder Mitglieder mit Geburtstag
 */
function birthday_sentence(array $kinder, ?int $now = null): string
{
    if ($kinder === []) {
        return '';
    }

    $namen = array_map(static function (array $mitglied) {
        return (string) $mitglied['nickname'];
    }, $kinder);

    if (count($kinder) === 1) {
        $alter = birthday_age((string) ($kinder[0]['birthdate'] ?? ''), $now);

        return $alter !== null
            ? $namen[0] . ' wird heute ' . $alter . '.'
            : $namen[0] . ' hat heute Geburtstag.';
    }

    return birthday_names_text($namen) . ' haben heute Geburtstag.';
}

/* --------------------------------------------------------------------- */
/* Versand                                                                 */
/* --------------------------------------------------------------------- */

/**
 * Beansprucht den Tag für diesen Aufruf.
 *
 * @param array<int, string> $namen
 * @return bool true, wenn dieser Aufruf verschicken soll
 */
function birthday_claim(string $tag, array $namen): bool
{
    return (bool) store_mutate('birthday_notices', static function (array &$rows) use ($tag, $namen) {
        foreach ($rows as $zeile) {
            if ((string) ($zeile['date'] ?? '') === $tag) {
                return false;
            }
        }

        $rows[] = [
            'date'    => $tag,
            'sent_at' => date('c'),
            'names'   => array_values($namen),
        ];

        // Nur die jüngsten Einträge aufheben, die Datei bleibt sonst ewig
        usort($rows, static function (array $a, array $b) {
            return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
        });
        if (count($rows) > BIRTHDAY_NOTICE_HISTORY) {
            $rows = array_slice($rows, -BIRTHDAY_NOTICE_HISTORY);
        }

        return true;
    });
}

/** Wurde für diesen Tag schon verschickt? Blick ohne Sperre, nur zum Abkürzen. */
function birthday_notice_sent(string $tag): bool
{
    foreach (store_read('birthday_notices') as $zeile) {
        if ((string) ($zeile['date'] ?? '') === $tag) {
            return true;
        }
    }

    return false;
}

/** Letzter verschickter Geburtstagsgruß, für die Verwaltung. */
function birthday_last_notice(): ?array
{
    $letzter = null;
    foreach (store_read('birthday_notices') as $zeile) {
        if ($letzter === null || strcmp((string) $zeile['date'], (string) $letzter['date']) > 0) {
            $letzter = $zeile;
        }
    }

    return $letzter;
}

/**
 * Verschickt die Geburtstagsgrüße des Tages, höchstens einmal je Tag.
 *
 * Gehört hinter push_detach() in eine Shutdown-Funktion – der Versand wartet
 * sonst auf die Push-Dienste, während jemand nur die Galerie ansehen wollte.
 */
function birthday_dispatch(?int $now = null): void
{
    if (!BIRTHDAY_NOTIFY) {
        return;
    }

    $now = $now ?? time();
    if ((int) date('G', $now) < BIRTHDAY_NOTIFY_HOUR) {
        return;
    }

    $tag = date('Y-m-d', $now);
    if (birthday_notice_sent($tag)) {
        return;
    }

    $kinder = birthdays_today($now);
    if ($kinder === []) {
        return;
    }

    $namen = array_map(static function (array $mitglied) {
        return (string) $mitglied['nickname'];
    }, $kinder);

    if (!birthday_claim($tag, $namen)) {
        return;
    }

    $eingeschaltet = push_recipients('birthdays');
    $kinderIds     = array_map(static function (array $mitglied) {
        return (string) $mitglied['id'];
    }, $kinder);

    // Alle anderen erfahren, wer heute gefeiert wird
    $andere = array_values(array_diff($eingeschaltet, $kinderIds));
    if ($andere !== []) {
        push_notify($andere, [
            'title' => 'Heute Geburtstag',
            'body'  => birthday_sentence($kinder, $now),
            'url'   => 'members.php',
            'tag'   => 'birthday',
        ]);
    }

    // Und die Geburtstagskinder bekommen einen Gruß
    foreach ($kinder as $mitglied) {
        $id = (string) $mitglied['id'];
        if (!in_array($id, $eingeschaltet, true)) {
            continue;
        }

        push_notify([$id], [
            'title' => 'Alles Gute!',
            'body'  => 'Herzlichen Glückwunsch zum Geburtstag, ' . $mitglied['nickname'] . '!',
            'url'   => 'index.php',
            'tag'   => 'birthday',
        ]);
    }
}

/**
 * Meldet den Versand für später an, wenn heute jemand Geburtstag hat.
 * Der Aufruf kostet nichts, solange nichts zu tun ist.
 *
 * @param array<int, array<string, mixed>> $kinder bereits ermittelte Geburtstagskinder
 */
function birthday_schedule_dispatch(array $kinder): void
{
    if (!BIRTHDAY_NOTIFY || $kinder === []) {
        return;
    }

    register_shutdown_function(static function () {
        push_detach();
        birthday_dispatch();
    });
}
